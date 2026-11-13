<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'council_sync_model.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'council_sync_software.php';

class CouncilSyncIssue extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly string $reason,
        public readonly array $details = []
    ) {
        parent::__construct($reason);
    }

    public function issueHash(): string
    {
        $details = $this->details;
        ksort($details);
        return hash('sha256', json_encode([$this->kind, $this->reason, $details], JSON_THROW_ON_ERROR));
    }

    public function description(): string
    {
        return Language::_('CouncilSync.!error.' . $this->reason, true)
            . ($this->details ? ' ' . json_encode($this->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : '');
    }
}

class CouncilSync extends CouncilSyncModel
{
    public const PAGE = 'List_of_councils_(Scouting_America)';
    public const REPOSITORY = 'trooptrailer/council-sync-blesta-plugin';
    public const RELEASE_API = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
    public const REMINDER_SECONDS = 604800;
    private const MAX_RESPONSE_BYTES = 4194304;
    private const MAX_WIKI_LINES = 20000;
    private const MAX_COUNCILS = 1000;
    private const MAX_CELL_BYTES = 8192;
    private const MAX_OPTIONS_BYTES = 65535;
    private ?int $lockedCompany = null;

    public function __construct()
    {
        parent::__construct();
        Loader::loadComponents($this, ['Record']);
        Loader::loadModels($this, ['Clients', 'Staff', 'StaffGroups', 'CronTasks', 'PluginManager']);
    }

    public static function parseWikitext(string $text): array
    {
        if (strlen($text) > self::MAX_RESPONSE_BYTES || substr_count($text, "\n") > self::MAX_WIKI_LINES) {
            self::rejectContent('page', 'size');
        }
        if (preg_match('//u', $text) !== 1) {
            self::rejectContent('page', 'encoding');
        }
        $text = self::replaceWikitext(
            '~<!--.*?-->|<ref\b[^>]*?/>|<ref\b[^>]*>.*?</ref\s*>~is',
            '',
            str_replace("\r\n", "\n", $text)
        );
        $tables = [];
        $table = [];
        $depth = 0;
        foreach (explode("\n", $text) as $line) {
            $line = ltrim($line);
            if (str_starts_with($line, '{|')) {
                if ($depth++ === 0) {
                    $table = [];
                }
            } elseif (str_starts_with($line, '|}')) {
                if ($depth === 0) {
                    throw new CouncilSyncIssue('fetch', 'parse');
                }
                if (--$depth === 0) {
                    $tables[] = $table;
                }
            } elseif ($depth === 1) {
                $table[] = $line;
            }
        }
        if ($depth !== 0) {
            throw new CouncilSyncIssue('fetch', 'parse');
        }

        $required = ['council number', 'council name', 'headquarters city', 'headquarters state'];
        foreach ($tables as $lines) {
            $headers = [];
            $rows = [];
            $row = [];
            $templates = $links = 0;
            foreach ($lines as $line) {
                if ($templates === 0 && $links === 0 && str_starts_with($line, '!') && !$row) {
                    foreach (self::splitCells($line, '!') as $header) {
                        $headers[] = strtolower(self::plainCell($header));
                    }
                } elseif ($templates === 0 && $links === 0 && str_starts_with($line, '|-')) {
                    if ($row) {
                        $rows[] = self::splitCells(implode("\n", $row), '|');
                        $row = [];
                    }
                } elseif (!str_starts_with($line, '|+') && ($row || str_starts_with($line, '|'))) {
                    $row[] = $line;
                }
                self::advanceDepth($line, $templates, $links);
            }
            if ($row) {
                $rows[] = self::splitCells(implode("\n", $row), '|');
            }
            $positions = [];
            foreach ($required as $heading) {
                $position = array_search($heading, $headers, true);
                if ($position === false) {
                    continue 2;
                }
                $positions[] = $position;
            }
            if ($templates !== 0 || $links !== 0) {
                throw new CouncilSyncIssue('fetch', 'parse');
            }
            if (count($rows) > self::MAX_COUNCILS) {
                self::rejectContent('rows', 'count');
            }
            $councils = [];
            $fields = ['number', 'name', 'city', 'state'];
            foreach ($rows as $index => $cells) {
                $values = [];
                foreach ($positions as $column => $position) {
                    if (!array_key_exists($position, $cells)) {
                        throw new CouncilSyncIssue('fetch', 'parse');
                    }
                    $field = $fields[$column];
                    $values[$field] = self::plainCell($cells[$position], $field, $index + 1);
                }
                $councils[] = self::validatedCouncil($values, $index + 1);
            }
            if (!$councils) {
                throw new CouncilSyncIssue('sanity', 'empty');
            }
            return $councils;
        }
        throw new CouncilSyncIssue('fetch', 'table_missing');
    }

    private static function advanceDepth(string $text, int &$templates, int &$links): void
    {
        for ($i = 0, $length = strlen($text); $i < $length - 1; $i++) {
            $pair = substr($text, $i, 2);
            if ($pair === '{{') {
                $templates++;
                $i++;
            } elseif ($pair === '}}') {
                $templates = max(0, $templates - 1);
                $i++;
            } elseif ($pair === '[[') {
                $links++;
                $i++;
            } elseif ($pair === ']]') {
                $links = max(0, $links - 1);
                $i++;
            }
        }
    }

    private static function splitCells(string $text, string $separator): array
    {
        $cells = [];
        $cell = '';
        $started = false;
        $templates = $links = 0;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $pair = substr($text, $i, 2);
            if (in_array($pair, ['{{', '}}', '[[', ']]'], true)) {
                self::advanceDepth($pair, $templates, $links);
                $cell .= $pair;
                $i++;
            } elseif (
                $templates === 0 && $links === 0 && $text[$i] === $separator
                && ($i === 0 || $text[$i - 1] === "\n" || $pair === $separator . $separator)
            ) {
                if ($started) {
                    $cells[] = $cell;
                }
                $cell = '';
                $started = true;
                if ($pair === $separator . $separator) {
                    $i++;
                }
            } else {
                $cell .= $text[$i];
            }
        }
        if ($started) {
            $cells[] = $cell;
        }
        return $cells;
    }

    private static function rejectContent(string $field, string $problem, ?int $row = null): never
    {
        $details = ['field' => $field, 'problem' => $problem];
        if ($row !== null) {
            $details['row'] = $row;
        }
        error_log('COUNCIL_SYNC_SOURCE_REJECTED field=' . $field . ' problem=' . $problem
            . ($row === null ? '' : ' row=' . $row));
        throw new CouncilSyncIssue('fetch', 'unsafe_content', $details);
    }

    private static function replaceWikitext(string $pattern, string $replacement, string $text): string
    {
        $result = preg_replace($pattern, $replacement, $text);
        if ($result === null) {
            throw new CouncilSyncIssue('fetch', 'parse');
        }
        return $result;
    }

    private static function plainCell(string $cell, string $field = 'header', ?int $row = null): string
    {
        if (strlen($cell) > self::MAX_CELL_BYTES) {
            self::rejectContent($field, 'size', $row);
        }
        if (preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F\x{007F}-\x{009F}\p{Cf}]~u', $cell) !== 0) {
            self::rejectContent($field, 'controls', $row);
        }
        if (preg_match('~\b(?:rowspan|colspan)\s*=\s*["\']?[2-9]~i', $cell)) {
            throw new CouncilSyncIssue('fetch', 'parse');
        }
        $cell = self::replaceWikitext('~^\s*(?:[\w:-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s|]+)\s*)+\|~', '', $cell);
        $linked = preg_replace_callback('~\[\[([^\[\]]+)\]\]~', static function ($match) {
            $parts = explode('|', $match[1]);
            return end($parts);
        }, $cell);
        if ($linked === null) {
            throw new CouncilSyncIssue('fetch', 'parse');
        }
        $cell = self::replaceWikitext('~\[(?:https?://\S+)\s+([^\]]+)\]~', '$1', $linked);
        $cell = self::replaceWikitext('~\{\{(?:nowrap|small|sort)\|(?:[^{}|]*\|)?([^{}]+)\}\}~i', '$1', $cell);
        if (str_contains($cell, '{{') || str_contains($cell, '[[')) {
            throw new CouncilSyncIssue('fetch', 'parse');
        }
        $cell = self::replaceWikitext('~<br\s*/?>~i', ' ', $cell);
        $cell = self::replaceWikitext('~\s+~u', ' ', str_replace(["'''", "''"], '', $cell));
        $cell = html_entity_decode($cell, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('~[\p{Cc}\p{Cf}]~u', $cell) !== 0) {
            self::rejectContent($field, 'controls', $row);
        }
        return trim(self::replaceWikitext('~\p{Zs}+~u', ' ', $cell));
    }

    private static function plainText(string $text, string $field, ?int $row = null): string
    {
        $limit = match ($field) {
            'state' => 64,
            'label' => 250,
            default => 200
        };
        if (strlen($text) > $limit * 4 || preg_match('//u', $text) !== 1) {
            self::rejectContent($field, 'encoding_or_size', $row);
        }
        if (preg_match('~[\p{Cc}\p{Cf}]~u', $text) !== 0) {
            self::rejectContent($field, 'controls', $row);
        }
        if (preg_match('~&(?:amp|lt|gt|quot|apos)~i', $text) !== 0) {
            self::rejectContent($field, 'encoding', $row);
        }
        $text = trim(self::replaceWikitext('~\p{Zs}+~u', ' ', $text));
        if (
            preg_match('~\A[\p{L}\p{M}\p{N} .,\x{0027}\x{0022}&()/\x{2010}-\x{2015}\x{2018}\x{2019}\x{201C}\x{201D}-]{1,'
                . $limit . '}\z~uD', $text) !== 1
            || preg_match('~\p{L}~u', $text) !== 1
        ) {
            self::rejectContent($field, 'text', $row);
        }
        return $text;
    }

    private static function validatedCouncil(mixed $row, int $index): array
    {
        if (!is_array($row)) {
            throw new CouncilSyncIssue('fetch', 'parse');
        }
        foreach (['number', 'name', 'city', 'state'] as $field) {
            if (!isset($row[$field]) || $row[$field] === '' || ($field !== 'number' && !is_string($row[$field]))) {
                throw new CouncilSyncIssue('fetch', 'parse');
            }
        }
        if (
            (!is_string($row['number']) && !is_int($row['number']))
            || preg_match('~\A[0-9]{1,4}\z~D', (string) $row['number']) !== 1 || (int) $row['number'] < 1
        ) {
            self::rejectContent('number', 'range', $index);
        }
        return [
            'number' => (string) (int) $row['number'],
            'name' => self::plainText($row['name'], 'name', $index),
            'city' => self::plainText($row['city'], 'city', $index),
            'state' => self::plainText($row['state'], 'state', $index)
        ];
    }

    private static function validateImportedOptions(array $options): void
    {
        if (!$options) {
            throw new CouncilSyncIssue('sanity', 'empty');
        }
        if (count($options) > self::MAX_COUNCILS) {
            self::rejectContent('options', 'count');
        }
        $index = 0;
        foreach ($options as $value => $label) {
            $index++;
            if (strlen((string) $value) > 240 || preg_match('~\A[a-z0-9]+(?:-[a-z0-9]+)*\z~D', (string) $value) !== 1) {
                self::rejectContent('value', 'slug', $index);
            }
            if (!is_string($label)) {
                self::rejectContent('label', 'type', $index);
            }
            if (self::plainText($label, 'label', $index) !== $label) {
                self::rejectContent('label', 'normalization', $index);
            }
        }
        $values = array_flip($options);
        if (count($values) !== count($options)) {
            throw new CouncilSyncIssue('collision', 'collision');
        }
        if (strlen(json_encode($values, JSON_THROW_ON_ERROR)) > self::MAX_OPTIONS_BYTES) {
            self::rejectContent('options', 'serialized_size');
        }
    }

    public static function stateAbbreviation(string $state): string
    {
        $state = self::plainText($state, 'state');
        $states = [
            'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
            'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
            'District of Columbia' => 'DC', 'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI',
            'Idaho' => 'ID', 'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA',
            'Kansas' => 'KS', 'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME',
            'Maryland' => 'MD', 'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN',
            'Mississippi' => 'MS', 'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE',
            'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM',
            'New York' => 'NY', 'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH',
            'Oklahoma' => 'OK', 'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI',
            'South Carolina' => 'SC', 'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX',
            'Utah' => 'UT', 'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA',
            'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY', 'Puerto Rico' => 'PR',
            'Guam' => 'GU', 'American Samoa' => 'AS', 'Northern Mariana Islands' => 'MP',
            'U.S. Virgin Islands' => 'VI'
        ];
        foreach ($states as $name => $abbreviation) {
            if (strcasecmp($state, $name) === 0 || strcasecmp($state, $abbreviation) === 0) {
                return $abbreviation;
            }
        }
        if (strcasecmp($state, 'n/a') === 0) {
            return '';
        }
        foreach (['Belgium', 'Japan'] as $country) {
            if (strcasecmp($state, $country) === 0) {
                return $country;
            }
        }
        throw new CouncilSyncIssue('fetch', 'state_unknown', ['field' => 'state']);
    }

    public static function generateOptions(array $rows): array
    {
        if (count($rows) > self::MAX_COUNCILS) {
            self::rejectContent('rows', 'count');
        }
        $rows = array_values($rows);
        foreach ($rows as $index => $row) {
            $rows[$index] = self::validatedCouncil($row, $index + 1);
        }
        usort($rows, static fn($a, $b) => strcasecmp($a['name'], $b['name']) ?: (int) $a['number'] <=> (int) $b['number']);
        $options = [];
        $collisions = [];
        foreach ($rows as $row) {
            $name = trim($row['name']);
            $state = self::stateAbbreviation(trim($row['state']));
            $number = (string) (int) $row['number'];
            $label = $name . ($state === '' ? '' : ', ' . $state) . ' (' . $number . ')';
            $stem = $name . ($state === '' ? '' : ' ' . $state) . ' ' . $number;
            $stem = preg_replace('~[\'\x{2018}\x{2019}]~u', '', $stem);
            $value = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($stem)), '-');
            if (array_key_exists($value, $options)) {
                $collisions[$value] = true;
            }
            $options[$value] = $label;
        }
        if ($collisions) {
            $values = array_keys($collisions);
            sort($values, SORT_STRING);
            throw new CouncilSyncIssue('collision', 'collision', ['values' => $values]);
        }
        self::validateImportedOptions($options);
        return $options;
    }

    public static function assertRowCount(int $count, ?int $previous, int $maxDrop): void
    {
        if ($maxDrop < 0 || $maxDrop > 99 || ($previous !== null && $previous < 1)) {
            throw new CouncilSyncIssue('settings', 'settings');
        }
        if ($count < 1) {
            throw new CouncilSyncIssue('sanity', 'empty');
        }
        if ($previous !== null && $count * 100 < $previous * (100 - $maxDrop)) {
            throw new CouncilSyncIssue('sanity', 'row_count', [
                'previous' => $previous, 'current' => $count, 'max_drop' => $maxDrop
            ]);
        }
    }

    public static function diff(array $before, array $after, array $clients): array
    {
        $changes = ['added' => [], 'removed' => [], 'labels' => []];
        foreach ($after as $value => $label) {
            if (!array_key_exists($value, $before)) {
                $changes['added'][$value] = $label;
            } elseif ($before[$value] !== $label) {
                $changes['labels'][$value] = ['before' => $before[$value], 'after' => $label];
            }
        }
        $conflicts = [];
        foreach ($before as $value => $label) {
            if (!array_key_exists($value, $after)) {
                $changes['removed'][$value] = $label;
                $affected = array_values(array_filter($clients, static fn($client) => $client['value'] === (string) $value));
                if ($affected) {
                    $conflicts[$value] = ['label' => $label, 'count' => count($affected), 'clients' => $affected];
                }
            }
        }
        ksort($conflicts, SORT_STRING);
        return ['changes' => $changes, 'conflicts' => $conflicts];
    }

    public static function conflictHash(array $conflicts): string
    {
        $pairs = [];
        ksort($conflicts, SORT_STRING);
        foreach ($conflicts as $value => $conflict) {
            $pairs[] = [(string) $value, (int) $conflict['count']];
        }
        return hash('sha256', self::json($pairs));
    }

    public static function resolveChoices(array $conflicts, array $after, array $choices): array
    {
        if (array_diff(array_keys($choices), array_keys($conflicts))) {
            throw new CouncilSyncIssue('resolution', 'choice');
        }
        $items = [];
        foreach ($conflicts as $old => $conflict) {
            $choice = $choices[$old] ?? null;
            if (!is_array($choice) || !in_array($choice['mode'] ?? null, ['accept', 'remap', 'clients'], true)) {
                throw new CouncilSyncIssue('resolution', 'choice');
            }
            $overrides = $choice['clients'] ?? [];
            if (!is_array($overrides)) {
                throw new CouncilSyncIssue('resolution', 'choice');
            }
            $ids = array_column($conflict['clients'], 'id');
            if (array_diff(array_keys($overrides), $ids)) {
                throw new CouncilSyncIssue('resolution', 'choice');
            }
            foreach ($conflict['clients'] as $client) {
                if ($choice['mode'] === 'accept') {
                    if (($choice['confirm_orphan'] ?? null) !== '1') {
                        throw new CouncilSyncIssue('resolution', 'orphan_confirmation');
                    }
                    $new = (string) $old;
                    $action = 'accepted-orphan';
                } else {
                    $new = $choice['mode'] === 'remap'
                        ? ($choice['target'] ?? null)
                        : ($overrides[$client['id']] ?? '');
                    if ($choice['mode'] === 'clients' && $new === '') {
                        $new = $choice['all_target'] ?? null;
                    }
                    if (!is_string($new) || !array_key_exists($new, $after)) {
                        throw new CouncilSyncIssue('resolution', 'target');
                    }
                    $action = 'remap';
                }
                $items[] = ['client_id' => $client['id'], 'old_value' => (string) $old, 'new_value' => $new, 'action' => $action];
            }
        }
        return $items;
    }

    public static function validateContact(string $contact): string
    {
        $contact = trim($contact);
        $parts = parse_url($contact);
        $scheme = $parts['scheme'] ?? null;
        if (
            $contact === '' || strlen($contact) > 255 || preg_match('~[\x00-\x20\x7f()]~', $contact)
            || (
                !filter_var($contact, FILTER_VALIDATE_EMAIL)
                && !(
                    in_array($scheme, ['http', 'https'], true) && filter_var($contact, FILTER_VALIDATE_URL)
                    && !isset($parts['user']) && !isset($parts['pass'])
                )
            )
        ) {
            throw new CouncilSyncIssue('settings', 'contact');
        }
        return $contact;
    }

    public static function userAgent(string $contact): string
    {
        return 'CouncilSync-Blesta/' . self::packageVersion() . ' (' . self::validateContact($contact) . ') PHP/' . PHP_VERSION;
    }

    public static function packageVersion(): string
    {
        $config = json_decode(file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.json'), true, 512, JSON_THROW_ON_ERROR);
        $version = $config['version'] ?? null;
        if (!is_string($version) || !preg_match('~^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$~D', $version)) {
            throw new CouncilSyncIssue('settings', 'version_manifest');
        }
        return $version;
    }

    protected function httpRequest(string $contact): array
    {
        $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'parse', 'page' => self::PAGE, 'prop' => 'wikitext', 'format' => 'json', 'formatversion' => 2
        ], '', '&', PHP_QUERY_RFC3986);
        return $this->httpGet($url, self::userAgent($contact), self::MAX_RESPONSE_BYTES);
    }

    protected function updateRequest(): array
    {
        return $this->httpGet(
            self::RELEASE_API,
            'CouncilSync-Blesta/' . self::packageVersion() . ' (https://github.com/' . self::REPOSITORY . ') PHP/' . PHP_VERSION,
            262144,
            ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2026-03-10']
        );
    }

    public function softwareRelease(): array
    {
        $response = $this->updateRequest();
        if ($response['status'] !== 200) {
            throw new CouncilSyncIssue('software', 'software_download');
        }
        $release = self::parseRelease($response['body']);
        $tag = substr($release['url'], strlen('https://github.com/' . self::REPOSITORY . '/releases/tag/'));
        $path = 'git/ref/tags/' . rawurlencode($tag);
        for ($depth = 0; $depth < 5; $depth++) {
            $response = $this->httpGet(
                'https://api.github.com/repos/' . self::REPOSITORY . '/' . $path,
                'CouncilSync-Blesta/' . self::packageVersion(), 65536,
                ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2026-03-10']
            );
            if ($response['status'] !== 200) {
                throw new CouncilSyncIssue('software', 'software_download');
            }
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $object = $data['object'] ?? null;
            if (!is_array($object) || !is_string($object['sha'] ?? null) || !preg_match('~^[a-f0-9]{40}$~D', $object['sha'])) {
                throw new CouncilSyncIssue('software', 'software_download');
            }
            if (($object['type'] ?? null) === 'commit') {
                return $release + ['commit' => $object['sha']];
            }
            if (($object['type'] ?? null) !== 'tag') {
                break;
            }
            $path = 'git/tags/' . $object['sha'];
        }
        throw new CouncilSyncIssue('software', 'software_download');
    }

    protected function httpGet(string $url, string $agent, int $limit, array $headers = ['Accept: application/json']): array
    {
        if (!extension_loaded('curl')) {
            throw new CouncilSyncIssue('fetch', 'curl_missing');
        }
        $body = '';
        $tooLarge = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_USERAGENT => $agent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body, &$tooLarge, $limit) {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }
        ]);
        try {
            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($tooLarge) {
                throw new CouncilSyncIssue('fetch', 'response_large');
            }
            if ($result === false) {
                throw new CouncilSyncIssue('fetch', 'transport', ['code' => curl_errno($handle)]);
            }
            return ['status' => $status, 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }

    public function fetchOptions(string $contact, ?int $previous, int $maxDrop): array
    {
        self::validateContact($contact);
        $response = $this->httpRequest($contact);
        if ($response['status'] !== 200) {
            throw new CouncilSyncIssue('fetch', 'http_status', ['status' => $response['status']]);
        }
        try {
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new CouncilSyncIssue('fetch', 'response');
        }
        if (
            !is_array($data) || isset($data['error']) || !isset($data['parse']['wikitext'], $data['parse']['title'])
            || !is_string($data['parse']['wikitext']) || !is_string($data['parse']['title'])
            || str_replace(' ', '_', $data['parse']['title']) !== self::PAGE
        ) {
            throw new CouncilSyncIssue('fetch', 'response');
        }
        $options = self::generateOptions(self::parseWikitext($data['parse']['wikitext']));
        self::assertRowCount(count($options), $previous, $maxDrop);
        return $options;
    }

    public function settings(int $company): array
    {
        $row = $this->Record->select()->from('council_sync_settings')->where('company_id', '=', $company)->fetch();
        if (!$row) {
            throw new CouncilSyncIssue('settings', 'not_installed');
        }
        $settings = (array) $row;
        foreach (['email_enabled', 'ticket_enabled', 'updates_enabled'] as $key) {
            if (!array_key_exists($key, $settings)) {
                throw new CouncilSyncIssue('settings', 'schema_upgrade');
            }
        }
        foreach (['company_id', 'field_id', 'staff_group_id', 'department_id', 'max_drop', 'frequency', 'previous_count', 'purge', 'email_enabled', 'ticket_enabled', 'updates_enabled'] as $key) {
            $settings[$key] = $settings[$key] === null ? null : (int) $settings[$key];
        }
        return $settings;
    }

    public function dropdownFields(int $company): array
    {
        $this->bindCoreConnection($this->Clients);
        return array_values(array_filter(
            $this->Clients->getCustomFields($company),
            static fn($field) => $field->type === 'select'
        ));
    }

    public function supportActive(int $company): bool
    {
        foreach ($this->PluginManager->getByDir('support_manager', $company) as $plugin) {
            if ((int) $plugin->enabled === 1) {
                return true;
            }
        }
        return false;
    }

    public function departments(int $company): array
    {
        if (!$this->supportActive($company)) {
            return [];
        }
        Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);
        return $this->SupportManagerDepartments->getAll($company);
    }

    public function saveSettings(int $company, array $input): void
    {
        $field = self::positiveInt($input['field_id'] ?? null);
        $emailEnabled = self::flag($input, 'email_enabled');
        $ticketEnabled = self::flag($input, 'ticket_enabled');
        $updatesEnabled = self::flag($input, 'updates_enabled');
        $group = ($input['staff_group_id'] ?? '') === '' ? null : self::positiveInt($input['staff_group_id']);
        $frequency = self::positiveInt($input['frequency'] ?? null);
        $drop = filter_var($input['max_drop'] ?? null, FILTER_VALIDATE_INT);
        if ($drop === false || $drop === null || $drop < 0 || $drop > 99) {
            throw new CouncilSyncIssue('settings', 'settings');
        }
        $contact = self::validateContact(is_string($input['contact'] ?? null) ? $input['contact'] : '');
        $this->locked($company, function () use ($company, $input, $field, $group, $frequency, $drop, $contact, $emailEnabled, $ticketEnabled, $updatesEnabled) {
            $this->readField($company, $field);
            $staffGroup = $group === null ? null : $this->StaffGroups->get($group);
            if (
                ($group !== null && (!$staffGroup || (int) $staffGroup->company_id !== $company))
                || ($emailEnabled && ($group === null || !$this->Staff->getAll($company, 'active', $group)))
            ) {
                throw new CouncilSyncIssue('settings', 'staff_group');
            }
            $old = $this->settings($company);
            $department = $old['department_id'];
            if ($this->supportActive($company)) {
                $department = ($input['department_id'] ?? '') === '' ? null : self::positiveInt($input['department_id']);
                if ($department !== null) {
                    Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);
                    $record = $this->SupportManagerDepartments->get($department);
                    if (!$record || (int) $record->company_id !== $company) {
                        throw new CouncilSyncIssue('settings', 'department');
                    }
                }
            }
            if ($ticketEnabled && !$emailEnabled && (!$department || !$this->supportActive($company))) {
                throw new CouncilSyncIssue('settings', 'department');
            }
            $purge = ($input['purge'] ?? null) === '1';
            if ($purge && ($input['purge_confirmation'] ?? null) !== 'DELETE COUNCIL SYNC DATA') {
                throw new CouncilSyncIssue('settings', 'purge_confirmation');
            }
            $run = $this->CronTasks->getTaskRunByKey('sync', 'council_sync');
            if (!$run || (int) $run->company_id !== $company) {
                throw new CouncilSyncIssue('settings', 'cron_missing');
            }
            $this->bindCoreConnection($this->CronTasks);
            $this->atomic(function () use ($company, $field, $group, $department, $contact, $drop, $frequency, $purge, $run, $emailEnabled, $ticketEnabled, $updatesEnabled) {
                $this->Record->where('company_id', '=', $company)->update('council_sync_settings', [
                    'field_id' => $field, 'staff_group_id' => $group, 'department_id' => $department,
                    'contact' => $contact, 'max_drop' => $drop, 'frequency' => $frequency, 'purge' => (int) $purge,
                    'email_enabled' => $emailEnabled, 'ticket_enabled' => $ticketEnabled, 'updates_enabled' => $updatesEnabled
                ]);
                $this->CronTasks->editTaskRun($run->task_run_id, ['interval' => $frequency]);
                if ($this->CronTasks->errors()) {
                    throw new CouncilSyncIssue('settings', 'cron_write');
                }
            });
        });
    }

    private static function flag(array $input, string $key): int
    {
        $value = $input[$key] ?? '0';
        if ($value !== '0' && $value !== '1') {
            throw new CouncilSyncIssue('settings', 'settings');
        }
        return (int) $value;
    }

    private static function positiveInt(mixed $value): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if ($result === false || $result === null || $result < 1 || $result > 4294967295) {
            throw new CouncilSyncIssue('settings', 'settings');
        }
        return $result;
    }

    public function bindCoreConnection(object $model): void
    {
        $model->Record->setConnection($this->Record->connect());
    }

    public function locked(int $company, callable $operation): mixed
    {
        if ($this->lockedCompany !== null) {
            if ($this->lockedCompany !== $company) {
                throw new CouncilSyncIssue('storage', 'company');
            }
            return $operation();
        }
        return CouncilSyncSoftware::guard($this->Record, function () use ($company, $operation) {
            $name = CouncilSyncSoftware::lockBase($this->Record) . ':' . $company;
            $result = $this->Record->query('SELECT GET_LOCK(?, 0) AS acquired', $name)->fetch(PDO::FETCH_OBJ);
            if (!$result || (int) $result->acquired !== 1) {
                throw new CouncilSyncIssue('storage', 'busy');
            }
            $this->lockedCompany = $company;
            try {
                return $operation();
            } finally {
                $this->lockedCompany = null;
                $released = $this->Record->query('SELECT RELEASE_LOCK(?) AS released', $name)->fetch(PDO::FETCH_OBJ);
                if (!$released || (int) $released->released !== 1) {
                    error_log('COUNCIL_SYNC_LOCK_RELEASE_FAILED company_id=' . $company);
                }
            }
        });
    }

    public function atomic(callable $operation): mixed
    {
        $pdo = $this->Record->connect();
        if ($pdo->inTransaction()) {
            throw new CouncilSyncIssue('storage', 'nested_transaction');
        }
        $this->Record->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->Record->begin();
        try {
            $result = $operation();
            $this->Record->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $this->Record->rollBack();
            }
            throw $error;
        }
    }

    private function readField(int $company, int $field): object
    {
        $this->bindCoreConnection($this->Clients);
        $record = $this->Clients->getCustomField($field, $company);
        if (!$record || $record->type !== 'select') {
            throw new CouncilSyncIssue('settings', 'field');
        }
        return $record;
    }

    private static function fromBlestaOptions(mixed $values): array
    {
        if ($values === null || $values === '') {
            return [];
        }
        if (!is_array($values)) {
            throw new CouncilSyncIssue('storage', 'options');
        }
        $options = [];
        foreach ($values as $label => $value) {
            if (!is_string($value) || array_key_exists($value, $options)) {
                throw new CouncilSyncIssue('collision', 'existing_collision');
            }
            $options[$value] = (string) $label;
        }
        return $options;
    }

    private function readState(int $company, int $field, bool $lock = false): array
    {
        $this->bindCoreConnection($this->Clients);
        if ($lock) {
            $this->Record->query('SELECT company_id FROM council_sync_settings WHERE company_id = ? FOR UPDATE', $company);
            $this->Record->query('SELECT id FROM client_fields WHERE id = ? FOR UPDATE', $field);
        }
        // This indexed range lock also blocks new field assignments until commit.
        $ids = $this->Record->query(
            'SELECT client_id FROM client_values WHERE client_field_id = ? ORDER BY client_id' . ($lock ? ' FOR UPDATE' : ''),
            $field
        )->fetchAll(PDO::FETCH_OBJ);
        // Establish the repeatable-read snapshot only after acquiring every lock.
        $record = $this->readField($company, $field);
        $clients = [];
        foreach ($ids as $id) {
            $found = false;
            $values = $this->Clients->getCustomFieldValues($id->client_id);
            if (!is_array($values)) {
                throw new CouncilSyncIssue('storage', 'value_read');
            }
            foreach ($values as $value) {
                if ((int) $value->id === $field) {
                    if (
                        !is_string($value->value)
                        || ((int) $value->value_encrypted === 1 && (int) $value->encrypted !== 1)
                    ) {
                        throw new CouncilSyncIssue('storage', 'value_read');
                    }
                    $clients[] = ['id' => (int) $id->client_id, 'value' => $value->value];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new CouncilSyncIssue('storage', 'value_read');
            }
        }
        $metadata = [];
        foreach (['client_group_id', 'name', 'link', 'is_lang', 'type', 'regex', 'show_client', 'read_only', 'encrypted'] as $key) {
            $metadata[$key] = $record->$key === null ? null : (string) $record->$key;
        }
        return [
            'field_id' => $field, 'options' => self::fromBlestaOptions($record->values),
            'default' => $record->default, 'signature' => hash('sha256', self::json($metadata)),
            'clients' => $clients, 'record' => $record
        ];
    }

    private static function stateHash(array $state): string
    {
        unset($state['record']);
        return hash('sha256', self::json($state));
    }

    public function preview(int $company): array
    {
        $settings = $this->settings($company);
        if (!$settings['field_id']) {
            throw new CouncilSyncIssue('settings', 'settings');
        }
        $state = $this->readState($company, $settings['field_id']);
        $after = $this->fetchOptions($settings['contact'], $settings['previous_count'], $settings['max_drop']);
        return array_merge(self::diff($state['options'], $after, $state['clients']), [
            'company_id' => $company, 'field_id' => $settings['field_id'], 'after' => $after,
            'state_hash' => self::stateHash($state), 'settings_hash' => hash('sha256', self::json($settings)),
            'fetched_at' => time()
        ]);
    }

    public function conflictClients(int $company, array $preview): array
    {
        if (($preview['company_id'] ?? null) !== $company) {
            throw new CouncilSyncIssue('storage', 'company');
        }
        $names = [];
        foreach ($preview['conflicts'] as $conflict) {
            foreach ($conflict['clients'] as $client) {
                $record = $this->Clients->get($client['id'], false);
                if (!$record) {
                    throw new CouncilSyncIssue('storage', 'stale');
                }
                $names[$client['id']] = trim($record->first_name . ' ' . $record->last_name);
            }
        }
        return $names;
    }

    public function apply(
        int $company,
        array $preview,
        array $choices,
        string $source,
        ?int $staff,
        bool $dryRun = false
    ): array {
        if (
            ($preview['company_id'] ?? null) !== $company || !in_array($source, ['cron', 'manual'], true)
            || ($source === 'manual' && ($staff === null || $staff < 1))
            || ($source === 'cron' && ($staff !== null || $choices))
            || !isset($preview['after'], $preview['field_id'], $preview['fetched_at'])
            || !is_array($preview['after']) || time() - $preview['fetched_at'] > 3600
        ) {
            throw new CouncilSyncIssue('storage', 'stale');
        }
        self::validateImportedOptions($preview['after']);
        return $this->locked($company, function () use ($company, $preview, $choices, $source, $staff, $dryRun) {
            return $this->atomic(function () use ($company, $preview, $choices, $source, $staff, $dryRun) {
                $state = $this->readState($company, $preview['field_id'], true);
                $settings = $this->settings($company);
                if (
                    !hash_equals($preview['state_hash'], self::stateHash($state))
                    || !hash_equals($preview['settings_hash'], hash('sha256', self::json($settings)))
                ) {
                    throw new CouncilSyncIssue('storage', 'stale');
                }
                self::assertRowCount(count($preview['after']), $settings['previous_count'], $settings['max_drop']);
                $diff = self::diff($state['options'], $preview['after'], $state['clients']);
                if ($source === 'cron' && $diff['conflicts']) {
                    return ['status' => 'conflicts', 'preview' => $preview];
                }
                $items = self::resolveChoices($diff['conflicts'], $preview['after'], $choices);
                $summary = self::summary($diff['changes'], $items);
                if ($dryRun) {
                    return ['status' => 'dry-run', 'summary' => $summary, 'items' => $items];
                }
                $default = $state['default'];
                if (
                    $default !== null && !array_key_exists($default, $preview['after'])
                    && ($default !== '' || array_key_exists('', $state['options']))
                ) {
                    $default = null;
                }
                $batch = null;
                if ($state['options'] !== $preview['after'] || $default !== $state['default'] || $items) {
                    $batch = $this->writeBatch($company, $state, $preview['after'], $default, $source, $staff, $summary);
                    $this->writeOptions($state['record'], $preview['after'], $default);
                    foreach ($items as $item) {
                        $this->writeItem($batch, $item);
                        if ($item['action'] === 'remap') {
                            $this->writeClient($preview['field_id'], $item['client_id'], $item['new_value']);
                        }
                    }
                }
                $this->Record->where('company_id', '=', $company)->update('council_sync_settings', [
                    'previous_count' => count($preview['after'])
                ]);
                return ['status' => $batch === null ? 'unchanged' : 'applied', 'batch_id' => $batch, 'summary' => $summary];
            });
        });
    }

    public function synchronize(int $company, string $source, ?int $staff, bool $dryRun = false): array
    {
        $preview = $this->preview($company);
        if ($dryRun || $preview['conflicts']) {
            return ['status' => $preview['conflicts'] ? 'conflicts' : 'dry-run', 'preview' => $preview];
        }
        return $this->apply($company, $preview, [], $source, $staff);
    }

    private function writeOptions(object $field, array $options, ?string $default): void
    {
        // Blesta accepts label=>value; the synchronization model uses value=>label.
        $values = array_flip($options);
        if (count($values) !== count($options)) {
            throw new CouncilSyncIssue('collision', 'collision');
        }
        $input = [
            'client_group_id' => $field->client_group_id, 'name' => $field->name,
            'type' => 'select', 'values' => $values
        ];
        if ($default !== $field->default) {
            $input['default'] = $default;
        }
        $this->Clients->editCustomField($field->id, $input);
        if ($this->Clients->errors()) {
            throw new CouncilSyncIssue('storage', 'field_write');
        }
        $saved = $this->Clients->getCustomField($field->id);
        if (!$saved || self::fromBlestaOptions($saved->values) !== $options || $saved->default !== $default) {
            throw new CouncilSyncIssue('storage', 'field_write');
        }
    }

    private function writeClient(int $field, int $client, string $value): void
    {
        $this->Clients->setCustomField($field, $client, $value);
        if ($this->Clients->errors()) {
            throw new CouncilSyncIssue('storage', 'client_write', ['client_id' => $client]);
        }

        $values = $this->Clients->getCustomFieldValues($client);
        if (!is_array($values)) {
            throw new CouncilSyncIssue('storage', 'client_write', ['client_id' => $client]);
        }
        foreach ($values as $saved) {
            if ((int) $saved->id === $field && $saved->value === $value) {
                return;
            }
        }
        throw new CouncilSyncIssue('storage', 'client_write', ['client_id' => $client]);
    }

    private function writeItem(int $batch, array $item): void
    {
        $item['old_value'] = $this->systemEncrypt($item['old_value']);
        $item['new_value'] = $this->systemEncrypt($item['new_value']);
        if (!is_string($item['old_value']) || !is_string($item['new_value'])) {
            throw new CouncilSyncIssue('storage', 'audit_crypto');
        }
        $this->Record->insert('council_sync_items', ['batch_id' => $batch] + $item);
    }

    private static function summary(array $changes, array $items): array
    {
        return [
            'added' => count($changes['added']), 'removed' => count($changes['removed']),
            'labels' => count($changes['labels']),
            'remapped' => count(array_filter($items, static fn($item) => $item['action'] === 'remap')),
            'accepted_orphans' => count(array_filter($items, static fn($item) => $item['action'] === 'accepted-orphan'))
        ];
    }

    private function writeBatch(
        int $company, array $before, array $after, ?string $default,
        string $source, ?int $staff, array $summary, ?int $rollbackOf = null
    ): int {
        $this->Record->insert('council_sync_batches', [
            'company_id' => $company, 'field_id' => $before['field_id'], 'created_at' => gmdate('Y-m-d H:i:s'),
            'source' => $source, 'staff_id' => $staff, 'summary' => self::json($summary),
            'before_options' => self::json($before['options']), 'after_options' => self::json($after),
            'before_default' => $before['default'], 'after_default' => $default,
            'field_signature' => $before['signature'], 'status' => 'applied',
            'kind' => $rollbackOf === null ? 'update' : 'rollback', 'rollback_of' => $rollbackOf
        ]);
        return (int) $this->Record->lastInsertId();
    }

    public function latestBatch(int $company): ?object
    {
        $batch = $this->Record->select()->from('council_sync_batches')->where('company_id', '=', $company)
            ->where('kind', '=', 'update')->where('status', '=', 'applied')->order(['id' => 'desc'])->limit(1)->fetch();
        return $batch ?: null;
    }

    public function history(int $company, int $page = 1): array
    {
        if ($page < 1) {
            throw new CouncilSyncIssue('storage', 'batch');
        }
        return $this->Record->select()->from('council_sync_batches')->where('company_id', '=', $company)
            ->order(['id' => 'desc'])->limit(20, ($page - 1) * 20)->fetchAll();
    }

    public function batch(int $company, int $id): object
    {
        $batch = $this->Record->select()->from('council_sync_batches')->where('company_id', '=', $company)
            ->where('id', '=', $id)->fetch();
        if (!$batch) {
            throw new CouncilSyncIssue('storage', 'batch');
        }
        return $batch;
    }

    public function batchItems(int $company, int $id): array
    {
        $this->batch($company, $id);
        $items = $this->Record->select()->from('council_sync_items')->where('batch_id', '=', $id)
            ->order(['client_id' => 'asc'])->fetchAll();
        foreach ($items as $item) {
            $item->old_value = $this->systemDecrypt($item->old_value);
            $item->new_value = $this->systemDecrypt($item->new_value);
            if (!is_string($item->old_value) || !is_string($item->new_value)) {
                throw new CouncilSyncIssue('storage', 'audit_crypto');
            }
        }
        return $items;
    }

    public function rollback(int $company, int $id, int $staff): int
    {
        if ($staff < 1) {
            throw new CouncilSyncIssue('storage', 'company');
        }
        return $this->locked($company, function () use ($company, $id, $staff) {
            $initial = $this->batch($company, $id);
            return $this->atomic(function () use ($company, $id, $staff, $initial) {
                $state = $this->readState($company, (int) $initial->field_id, true);
                $batch = $this->batch($company, $id);
                $latest = $this->latestBatch($company);
                if (
                    !$latest || (int) $latest->id !== $id || $batch->kind !== 'update' || $batch->status !== 'applied'
                    || $batch->field_id !== $initial->field_id
                ) {
                    throw new CouncilSyncIssue('rollback', 'rollback_latest');
                }
                $before = json_decode($batch->before_options, true, 512, JSON_THROW_ON_ERROR);
                $after = json_decode($batch->after_options, true, 512, JSON_THROW_ON_ERROR);
                if ($state['options'] !== $after || $state['default'] !== $batch->after_default || $state['signature'] !== $batch->field_signature) {
                    throw new CouncilSyncIssue('rollback', 'rollback_changed');
                }
                $items = $this->batchItems($company, $id);
                $current = array_column($state['clients'], 'value', 'id');
                $restored = [];
                foreach ($items as $item) {
                    if (!array_key_exists($item->client_id, $current) || $current[$item->client_id] !== $item->new_value) {
                        throw new CouncilSyncIssue('rollback', 'rollback_changed');
                    }
                    $restored[$item->client_id] = $item->old_value;
                }
                foreach ($current as $client => $value) {
                    if (isset($after[$value]) && !isset($before[$value]) && !array_key_exists($client, $restored)) {
                        throw new CouncilSyncIssue('rollback', 'rollback_orphan', ['client_id' => $client]);
                    }
                }
                $audit = $this->writeBatch(
                    $company, $state, $before, $batch->before_default, 'manual', $staff,
                    ['rollback_of' => $id, 'clients' => count($items)], $id
                );
                $this->writeOptions($state['record'], $before, $batch->before_default);
                foreach ($items as $item) {
                    if ($item->action === 'remap') {
                        $this->writeClient((int) $batch->field_id, (int) $item->client_id, $item->old_value);
                    }
                    $this->writeItem($audit, [
                        'client_id' => $item->client_id,
                        'old_value' => $item->new_value, 'new_value' => $item->old_value, 'action' => $item->action
                    ]);
                }
                $this->Record->where('id', '=', $id)->where('company_id', '=', $company)
                    ->update('council_sync_batches', ['status' => 'rolled back']);
                return $audit;
            });
        });
    }

    public function issueRecord(int $company, int $field, string $hash): ?object
    {
        $issue = $this->Record->select()->from('council_sync_issues')->where('company_id', '=', $company)
            ->where('field_id', '=', $field)->where('hash', '=', $hash)->fetch();
        return $issue ?: null;
    }

    public function now(): int
    {
        return time();
    }

    public function noticeDue(?string $last): bool
    {
        if ($last === null) {
            return true;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $last, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $last) {
            throw new CouncilSyncIssue('storage', 'notice_state');
        }
        return $this->now() - $date->getTimestamp() >= self::REMINDER_SECONDS;
    }

    public function prepareNotice(int $company, int $field, string $hash, string $kind): object
    {
        $issue = $this->issueRecord($company, $field, $hash);
        if (!$issue) {
            $this->Record->insert('council_sync_issues', [
                'company_id' => $company, 'field_id' => $field, 'hash' => $hash, 'kind' => $kind,
                'ticket_id' => null, 'notified_at' => null, 'email_notified_at' => null, 'ticket_notified_at' => null,
                'emailed_staff' => null
            ]);
            $issue = $this->issueRecord($company, $field, $hash);
        }
        if (
            !$issue || !property_exists($issue, 'email_notified_at')
            || !property_exists($issue, 'ticket_notified_at') || !property_exists($issue, 'emailed_staff')
        ) {
            throw new CouncilSyncIssue('storage', 'schema_upgrade');
        }
        return $issue;
    }

    public function recordEmailNotice(int $company, int $field, string $hash): void
    {
        $now = gmdate('Y-m-d H:i:s', $this->now());
        $this->Record->where('company_id', '=', $company)->where('field_id', '=', $field)->where('hash', '=', $hash)
            ->update('council_sync_issues', ['email_notified_at' => $now, 'notified_at' => $now]);
    }

    public function emailDeliveries(object $issue): array
    {
        if ($issue->emailed_staff === null) {
            return [];
        }
        try {
            $deliveries = json_decode($issue->emailed_staff, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new CouncilSyncIssue('storage', 'notice_state');
        }
        if (!is_array($deliveries)) {
            throw new CouncilSyncIssue('storage', 'notice_state');
        }
        foreach ($deliveries as $staff => $at) {
            if (filter_var($staff, FILTER_VALIDATE_INT) === false || (int) $staff < 1 || !is_string($at)) {
                throw new CouncilSyncIssue('storage', 'notice_state');
            }
        }
        return $deliveries;
    }

    public function recordStaffEmail(int $company, int $field, string $hash, int $staff): void
    {
        $issue = $this->issueRecord($company, $field, $hash);
        if (!$issue || $staff < 1) {
            throw new CouncilSyncIssue('storage', 'notice_state');
        }
        $deliveries = $this->emailDeliveries($issue);
        $deliveries[$staff] = gmdate('Y-m-d H:i:s', $this->now());
        $this->Record->where('company_id', '=', $company)->where('field_id', '=', $field)->where('hash', '=', $hash)
            ->update('council_sync_issues', ['emailed_staff' => self::json($deliveries)]);
    }

    public function recordTicketNotice(int $company, int $field, string $hash, int $ticket, bool $created): void
    {
        $values = ['ticket_notified_at' => gmdate('Y-m-d H:i:s', $this->now()), 'ticket_id' => $ticket];
        if ($created) {
            $values['notified_at'] = $values['ticket_notified_at'];
        }
        $this->Record->where('company_id', '=', $company)->where('field_id', '=', $field)->where('hash', '=', $hash)
            ->update('council_sync_issues', $values);
    }

    public static function parseRelease(string $body): array
    {
        try {
            $release = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new CouncilSyncIssue('update', 'release_response');
        }
        if (
            !is_array($release) || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false
            || !is_string($release['tag_name'] ?? null) || strlen($release['tag_name']) > 64
            || !preg_match('~^v?((0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*))$~D', $release['tag_name'], $match)
            || ($release['html_url'] ?? null) !== 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . $release['tag_name']
        ) {
            throw new CouncilSyncIssue('update', 'release_response');
        }
        return ['version' => $match[1], 'url' => $release['html_url']];
    }

    public function versionStatus(int $company): array
    {
        $instances = $this->PluginManager->getByDir('council_sync', $company);
        if (count($instances) !== 1 || !is_string($instances[0]->version ?? null)) {
            throw new CouncilSyncIssue('settings', 'not_installed');
        }
        $installed = $instances[0]->version;
        $package = self::packageVersion();
        $cache = $this->Record->select()->from('council_sync_updates')->where('company_id', '=', $company)->fetch();
        $latest = $cache ? $cache->latest_version : null;
        $outdated = $latest !== null && (version_compare($package, $latest, '<') || version_compare($installed, $latest, '<'));
        return [
            'installed' => $installed, 'package' => $package, 'latest' => $latest,
            'release_url' => $cache ? $cache->release_url : null,
            'checked_at' => $cache ? $cache->checked_at : null,
            'last_success_at' => $cache ? $cache->last_success_at : null,
            'error_code' => $cache ? $cache->error_code : null,
            'status' => !$cache ? 'unchecked' : ($cache->error_code !== null ? 'unavailable' : ($outdated ? 'outdated' : 'current')),
            'outdated' => $outdated, 'upgrade_required' => $installed !== $package
        ];
    }

    public function checkForUpdates(int $company, bool $manual = false): array
    {
        return $this->locked($company, function () use ($company, $manual) {
            if (!$manual && !$this->settings($company)['updates_enabled']) {
                return $this->versionStatus($company);
            }
            $old = $this->Record->select()->from('council_sync_updates')->where('company_id', '=', $company)->fetch();
            $values = [
                'checked_at' => gmdate('Y-m-d H:i:s', $this->now()),
                'last_success_at' => $old ? $old->last_success_at : null,
                'latest_version' => $old ? $old->latest_version : null,
                'release_url' => $old ? $old->release_url : null, 'error_code' => null
            ];
            try {
                $response = $this->updateRequest();
                if ($response['status'] !== 200) {
                    throw new CouncilSyncIssue('update', 'release_http', ['status' => $response['status']]);
                }
                $release = self::parseRelease($response['body']);
                $values['latest_version'] = $release['version'];
                $values['release_url'] = $release['url'];
                $values['last_success_at'] = $values['checked_at'];
            } catch (CouncilSyncIssue $error) {
                $values['error_code'] = $error->reason === 'release_http' ? 'http_' . $error->details['status'] : $error->reason;
                error_log('COUNCIL_SYNC_UPDATE_CHECK_FAILED company_id=' . $company . ' reason=' . $values['error_code']);
            }
            if ($old) {
                $this->Record->where('company_id', '=', $company)->update('council_sync_updates', $values);
            } else {
                $this->Record->insert('council_sync_updates', ['company_id' => $company] + $values);
            }
            return $this->versionStatus($company);
        });
    }

    public static function json(array $value): string
    {
        return json_encode($value ?: (object) [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function installTables(int $company): void
    {
        CouncilSyncSoftware::installControl($this->Record);
        foreach (self::schema() as $sql) {
            $this->Record->query($sql);
        }
        $this->upgradeNoticeColumns();
        $exists = $this->Record->select('company_id')->from('council_sync_settings')->where('company_id', '=', $company)->fetch();
        if (!$exists) {
            $this->Record->insert('council_sync_settings', [
                'company_id' => $company, 'field_id' => null, 'staff_group_id' => null, 'department_id' => null,
                'contact' => '', 'max_drop' => 10, 'frequency' => 1440, 'previous_count' => null, 'purge' => 0,
                'email_enabled' => 1, 'ticket_enabled' => 1, 'updates_enabled' => 1
            ]);
        }
        $this->Record->query(
            'UPDATE council_sync_issues SET'
            . ' email_notified_at = CASE WHEN ticket_id IS NULL THEN notified_at ELSE NULL END,'
            . ' ticket_notified_at = CASE WHEN ticket_id IS NOT NULL THEN notified_at ELSE NULL END'
            . ' WHERE company_id = ? AND notified_at IS NOT NULL AND email_notified_at IS NULL AND ticket_notified_at IS NULL',
            $company
        );
    }

    private function upgradeNoticeColumns(): void
    {
        $definitions = [
            'council_sync_settings' => [
                'email_enabled' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                'ticket_enabled' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                'updates_enabled' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1'
            ],
            'council_sync_issues' => [
                'email_notified_at' => 'DATETIME NULL', 'ticket_notified_at' => 'DATETIME NULL',
                'emailed_staff' => 'MEDIUMTEXT NULL'
            ]
        ];
        foreach ($definitions as $table => $columns) {
            $existing = $this->Record->query(
                'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $table
            )->fetchAll(PDO::FETCH_OBJ);
            $names = array_column($existing, 'name');
            foreach ($columns as $column => $definition) {
                if (!in_array($column, $names, true)) {
                    $this->Record->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                }
            }
            if ($table === 'council_sync_issues') {
                foreach ($existing as $column) {
                    if ($column->name === 'notified_at' && $column->nullable === 'NO') {
                        $this->Record->query('ALTER TABLE council_sync_issues MODIFY notified_at DATETIME NULL');
                    }
                }
            }
        }
    }

    public function uninstallTables(int $company, bool $lastInstance): void
    {
        if ($lastInstance && $this->settings($company)['purge'] === 1) {
            foreach (['council_sync_items', 'council_sync_batches', 'council_sync_issues', 'council_sync_updates', 'council_sync_settings', 'council_sync_software'] as $table) {
                $this->Record->query('DROP TABLE `' . $table . '`');
            }
        }
    }

    private static function schema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS council_sync_settings (
                company_id INT UNSIGNED NOT NULL PRIMARY KEY, field_id INT UNSIGNED NULL,
                staff_group_id INT UNSIGNED NULL, department_id INT UNSIGNED NULL, contact VARCHAR(255) NOT NULL,
                max_drop TINYINT UNSIGNED NOT NULL DEFAULT 10, frequency INT UNSIGNED NOT NULL DEFAULT 1440,
                previous_count INT UNSIGNED NULL, `purge` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                email_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1, ticket_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
                updates_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS council_sync_batches (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, company_id INT UNSIGNED NOT NULL,
                field_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, source ENUM('cron','manual') NOT NULL,
                staff_id INT UNSIGNED NULL, summary TEXT NOT NULL, before_options MEDIUMTEXT NOT NULL,
                after_options MEDIUMTEXT NOT NULL, before_default TEXT NULL, after_default TEXT NULL,
                field_signature CHAR(64) NOT NULL, status ENUM('applied','rolled back') NOT NULL,
                kind ENUM('update','rollback') NOT NULL, rollback_of BIGINT UNSIGNED NULL, KEY company_id_id (company_id,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS council_sync_items (
                batch_id BIGINT UNSIGNED NOT NULL, client_id INT UNSIGNED NOT NULL,
                old_value MEDIUMTEXT NOT NULL, new_value MEDIUMTEXT NOT NULL, action ENUM('remap','accepted-orphan') NOT NULL,
                PRIMARY KEY (batch_id,client_id),
                FOREIGN KEY (batch_id) REFERENCES council_sync_batches(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS council_sync_issues (
                company_id INT UNSIGNED NOT NULL, field_id INT UNSIGNED NOT NULL, hash CHAR(64) NOT NULL,
                kind VARCHAR(32) NOT NULL, ticket_id BIGINT UNSIGNED NULL, notified_at DATETIME NULL,
                email_notified_at DATETIME NULL, ticket_notified_at DATETIME NULL, emailed_staff MEDIUMTEXT NULL,
                PRIMARY KEY (company_id,field_id,hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS council_sync_updates (
                company_id INT UNSIGNED NOT NULL PRIMARY KEY, checked_at DATETIME NOT NULL,
                last_success_at DATETIME NULL, latest_version VARCHAR(64) NULL, release_url TEXT NULL, error_code VARCHAR(64) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
    }
}
