<?php

namespace App\Rabbit;

/**
 * Business names for queues. A broker only knows technical names ("nota_fiscal_arquivos_1_0_0");
 * the catalog is a JSON file that says what each queue means to the business, grouped in areas.
 *
 * Rules are regexes tested in order, first match wins. Named groups become placeholders in the name,
 * e.g. "^export_(?<instancia>.+)$" + "Exportação · {instancia}". Without a file, or for queues no rule
 * matches, only the role (main / waiting for retry / failures) is guessed from the queue name.
 *
 * An area marked "generica" (a catch-all, shared infrastructure) never counts as the "main area" of an instance.
 *
 * Instances ("acme.empresa.com.br") and workers ("app-pdf@10.0.0.2") get a name people recognise:
 * "instancias.nomes" maps an instance to its display name, "workers" are regex rules that name a process and its
 * service. Without them an instance is cleaned ("instancias.remover") and written as a word ("acme" => "Acme").
 */
final class Catalog
{
    public const ROLE_MAIN = 'principal';

    public const ROLE_WAITING = 'espera';

    public const ROLE_FAILURES = 'falhas';

    /** A queue that has no consumer by design (read by something else, or a catch-all): no "nobody is consuming" alert. */
    public const ROLE_PASSIVE = 'passiva';

    /** @var list<array{regex: string, name: ?string, description: ?string, category: ?string, role: ?string}> */
    private array $rules = [];

    /** @var array<string, array{name: string, description: ?string, color: ?string, generic: bool}> */
    private array $categories = [];

    /** @var list<string> */
    private array $strip = [];

    /** @var array<string, string> cleaned instance (lowercase) => display name */
    private array $instanceNames = [];

    /** @var list<array{regex: string, name: ?string, service: ?string, description: ?string}> */
    private array $workerRules = [];

    /** @var array<string, string> */
    private array $vocabulary = [];

    /** @var list<array{id: string, title: string, kind: string, description: ?string, queues: list<string>, rules: list<array<string, mixed>>}> */
    private array $panels = [];

    private ?int $loadedAt = null;

    public function __construct(private readonly ?string $path) {}

    /** @return list<array{key: string, name: string, description: ?string, color: ?string, generic: bool}> */
    public function categories(): array
    {
        $this->load();

        $out = [];
        foreach ($this->categories as $key => $category) {
            $out[] = ['key' => $key, ...$category];
        }

        return $out;
    }

    /**
     * Identifies the catalog contents (its file's modification time). Anything cached from the catalog must include
     * it, because the web app and the tap run in separate processes and may not see the same file.
     */
    public function version(): string
    {
        if ($this->path === null || $this->path === '') {
            return 'none';
        }
        clearstatcache(true, $this->path);

        return is_file($this->path) ? (string) filemtime($this->path) : 'none';
    }

    /** @return array<string, string> plain-language words for codes ("pdf.gerar" => "geração de PDF") */
    public function vocabulary(): array
    {
        $this->load();

        return $this->vocabulary;
    }

    /**
     * The name people know an instance by: "acme.empresa.com.br" and "acme_empresa_com_br" both become "Acme".
     * Cleans the suffixes listed in "instancias.remover", then uses "instancias.nomes" when it has the instance,
     * and otherwise writes a plain word capitalised. Anything still odd (dots, slashes) is left as it came.
     */
    public function instanceLabel(string $instance): string
    {
        $this->load();

        $clean = $instance;
        foreach ($this->strip as $regex) {
            $clean = SafeRegex::replace($regex, '', $clean);
        }
        $clean = trim($clean, ' ._-');
        if ($clean === '') {
            return $instance;
        }

        return $this->instanceNames[mb_strtolower($clean)] ?? $this->capitalise($clean);
    }

    /**
     * Name and service of a worker process, from the "workers" rules. {usuario}, {host} and any named group
     * of the rule can be used in the name. Without a rule: no name, and the service is the user part.
     *
     * @return array{name: ?string, service: ?string, description: ?string}
     */
    public function describeWorker(string $worker, ?string $user = null, ?string $host = null): array
    {
        $this->load();

        foreach ($this->workerRules as $rule) {
            if (! SafeRegex::match($rule['regex'], $worker, $m)) {
                continue;
            }
            $values = ['usuario' => $user ?? '', 'host' => $host ?? ''];
            foreach ($m as $key => $value) {
                if (is_string($key) && $value !== '') {
                    $values[$key] = $value;
                }
            }
            $fill = fn (?string $text) => $text === null ? null : preg_replace_callback('/\{(\w+)\}/', fn ($x) => $values[$x[1]] ?? $x[0], $text);

            return ['name' => $fill($rule['name']), 'service' => $fill($rule['service']), 'description' => $rule['description']];
        }

        return ['name' => null, 'service' => null, 'description' => null];
    }

    private function capitalise(string $text): string
    {
        if (! preg_match('/^[\p{L}\p{N}_\-]+$/u', $text)) {
            return $text;
        }
        $words = array_values(array_filter(preg_split('/[_\-]+/', $text) ?: []));

        // a lone short word is an acronym (FIP, Globex); otherwise each word is capitalised ("meu_cliente" => "Meu Cliente")
        if (count($words) === 1 && mb_strlen($words[0]) <= 3) {
            return mb_strtoupper($words[0]);
        }

        return implode(' ', array_map(fn ($w) => mb_convert_case($w, MB_CASE_TITLE), $words));
    }

    /** @return list<array{id: string, title: string, kind: string, description: ?string, queues: list<string>, rules: list<array<string, mixed>>}> */
    public function panels(): array
    {
        $this->load();

        return $this->panels;
    }

    /** @return array{id: string, title: string, kind: string, description: ?string, queues: list<string>, rules: list<array<string, mixed>>}|null */
    public function panel(string $id): ?array
    {
        foreach ($this->panels() as $panel) {
            if ($panel['id'] === $id) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * What the UI needs to draw a tab per panel: the panel and the real queue names it covers.
     *
     * @param  list<string>  $queueNames
     * @return list<array{id: string, title: string, kind: string, description: ?string, queues: list<string>}>
     */
    public function panelsMeta(array $queueNames): array
    {
        return array_map(fn (array $p) => [
            'id' => $p['id'],
            'title' => $p['title'],
            'kind' => $p['kind'],
            'description' => $p['description'],
            'queues' => $this->queuesOf($p, $queueNames),
        ], $this->panels());
    }

    /**
     * @param  array{queues: list<string>}  $panel
     * @param  list<string>  $queueNames
     * @return list<string>
     */
    public function queuesOf(array $panel, array $queueNames): array
    {
        return array_values(array_filter($queueNames, function (string $name) use ($panel) {
            foreach ($panel['queues'] as $regex) {
                if (SafeRegex::match($regex, $name)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<string>  $queueNames
     * @return array<string, array{name: ?string, description: ?string, category: ?string, role: string, instance: ?string}>
     */
    public function describeMany(array $queueNames): array
    {
        $this->load();

        $out = [];
        foreach ($queueNames as $name) {
            $out[$name] = $this->lookup($name);
        }

        return $out;
    }

    /** @return array{name: ?string, description: ?string, category: ?string, role: string, instance: ?string} */
    public function describe(string $queue): array
    {
        $this->load();

        return $this->lookup($queue);
    }

    /** @return array{name: ?string, description: ?string, category: ?string, role: string, instance: ?string} */
    private function lookup(string $queue): array
    {
        foreach ($this->rules as $rule) {
            if (! SafeRegex::match($rule['regex'], $queue, $m)) {
                continue;
            }

            $groups = [];
            foreach ($m as $key => $value) {
                if (is_string($key) && $value !== '') {
                    $groups[$key] = $this->clean($key, $value);
                }
            }
            $name = $rule['name'];
            foreach ($groups as $key => $value) {
                $name = $name === null ? null : str_replace('{'.$key.'}', $value, $name);
            }

            return [
                'name' => $name,
                'description' => $rule['description'],
                'category' => $rule['category'],
                'role' => $rule['role'] ?? self::guessRole($queue),
                'instance' => $groups['instancia'] ?? null,
            ];
        }

        return ['name' => null, 'description' => null, 'category' => null, 'role' => self::guessRole($queue), 'instance' => null];
    }

    /** Without a rule, the name still says a lot: "...aguardando", "...dlq", "...falhas". */
    public static function guessRole(string $queue): string
    {
        if (preg_match('/(^|[._-])(dlq|falhas?|failed|dead|erros?)([._-]|$)/i', $queue)) {
            return self::ROLE_FAILURES;
        }
        if (preg_match('/(^|[._-])(aguardando|espera|retry|retentativa|wait|waiting|delay)([._-]|$)/i', $queue)) {
            return self::ROLE_WAITING;
        }

        return self::ROLE_MAIN;
    }

    private function clean(string $group, string $value): string
    {
        return $group === 'instancia' ? $this->instanceLabel($value) : $value;
    }

    private function load(): void
    {
        if ($this->path === null || $this->path === '') {
            return;
        }
        clearstatcache(true, $this->path);
        $mtime = is_file($this->path) ? filemtime($this->path) : false;
        if ($mtime === false) {
            $this->rules = $this->categories = $this->strip = [];
            $this->loadedAt = null;

            return;
        }
        if ($mtime === $this->loadedAt) {
            return;
        }

        $this->loadedAt = $mtime;
        $data = json_decode((string) file_get_contents($this->path), true);
        $data = is_array($data) ? $data : [];

        $this->categories = [];
        foreach ((array) ($data['categorias'] ?? []) as $key => $c) {
            if (is_array($c) && isset($c['nome'])) {
                $this->categories[(string) $key] = ['name' => (string) $c['nome'], 'description' => $c['descricao'] ?? null, 'color' => $c['cor'] ?? null, 'generic' => (bool) ($c['generica'] ?? false)];
            }
        }

        $this->strip = array_values(array_filter(array_map(fn ($r) => is_string($r) ? SafeRegex::wrap($r) : null, (array) ($data['instancias']['remover'] ?? []))));

        $this->instanceNames = [];
        foreach ((array) ($data['instancias']['nomes'] ?? []) as $key => $display) {
            if (is_string($display) && $display !== '') {
                $this->instanceNames[mb_strtolower(trim((string) $key))] = $display;
            }
        }

        $this->workerRules = [];
        foreach ((array) ($data['workers'] ?? []) as $rule) {
            $regex = is_array($rule) && isset($rule['padrao']) ? SafeRegex::wrap((string) $rule['padrao']) : null;
            if ($regex !== null && SafeRegex::isValid($regex)) {
                $this->workerRules[] = ['regex' => $regex, 'name' => $rule['nome'] ?? null, 'service' => $rule['servico'] ?? null, 'description' => $rule['descricao'] ?? null];
            }
        }

        $this->vocabulary = [];
        foreach ((array) ($data['vocabulario'] ?? []) as $code => $word) {
            if (is_string($word)) {
                $this->vocabulary[(string) $code] = $word;
            }
        }

        $this->panels = $this->loadPanels((array) ($data['paineis'] ?? []));

        $this->rules = [];
        foreach ((array) ($data['filas'] ?? []) as $rule) {
            if (! is_array($rule) || ! isset($rule['padrao'])) {
                continue;
            }
            $regex = SafeRegex::wrap((string) $rule['padrao']);
            if (! SafeRegex::isValid($regex)) {
                continue;
            }
            $role = $rule['papel'] ?? null;
            $this->rules[] = [
                'regex' => $regex,
                'name' => isset($rule['nome']) ? (string) $rule['nome'] : null,
                'description' => $rule['descricao'] ?? null,
                'category' => $rule['categoria'] ?? null,
                'role' => in_array($role, [self::ROLE_MAIN, self::ROLE_WAITING, self::ROLE_FAILURES, self::ROLE_PASSIVE], true) ? $role : null,
            ];
        }
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @return list<array{id: string, title: string, kind: string, description: ?string, queues: list<string>, rules: list<array<string, mixed>>}>
     */
    private function loadPanels(array $raw): array
    {
        $panels = [];
        foreach ($raw as $p) {
            if (! is_array($p) || ! isset($p['id'], $p['titulo'])) {
                continue;
            }
            $rules = [];
            foreach ((array) ($p['regras'] ?? []) as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $regex = isset($r['chave']) ? SafeRegex::wrap((string) $r['chave']) : null;
                if ($regex !== null && ! SafeRegex::isValid($regex)) {
                    continue;
                }
                $rules[] = [
                    'regex' => $regex,
                    'when' => is_array($r['quando'] ?? null) ? $r['quando'] : [],
                    'status' => $r['status'] ?? null,
                    'title' => $r['titulo'] ?? null,
                    'text' => $r['texto'] ?? null,
                    'fields' => is_array($r['campos'] ?? null) ? $r['campos'] : [],
                ];
            }
            $panels[] = [
                'id' => (string) $p['id'],
                'title' => (string) $p['titulo'],
                'kind' => ($p['tipo'] ?? 'resultados') === 'erros' ? 'erros' : 'resultados',
                'description' => $p['descricao'] ?? null,
                'queues' => array_values(array_filter(array_map(fn ($q) => is_string($q) ? SafeRegex::wrap($q) : null, (array) ($p['filas'] ?? [])), fn ($q) => $q !== null && SafeRegex::isValid($q))),
                'rules' => $rules,
            ];
        }

        return $panels;
    }
}
