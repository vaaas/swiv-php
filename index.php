<?php
/** @template T */
class Arr implements IteratorAggregate
{
    /** @param list<T> $xs */
    public function __construct(private array $xs) { }

    /**
     * @template X
     * @param    list<X> $xs
     * @return   self<X>
     */
    public static function of(array $xs): self
    {
        return new self($xs);
    }

    /** @return Traversable<T> */
    public function getIterator(): Traversable
    {
        foreach ($this->xs as $x) yield $x;
    }

    /**
     * @template U
     * @param    callable(T): U $f
     * @return   self<U>
     */
     public function map(callable $f): self
     {
         $ys = [];
         foreach ($this->xs as $x) $ys[] = $f($x);
         return self::of($ys);
     }

    /**
     * @param  callable(T): bool $f
     * @return self<T>
     */
    public function filter(callable $f): self
    {
        $ys = [];
        foreach ($this->xs as $x)
        {
            if ($f($x)) $ys[] = $x;
        }
        return self::of($ys);
    }

     /**
      * @template U
      * @param    callable(T): U|null $f
      * @return   self<U>
      */
     public function filterMap(callable $f): self
     {
         $ys = [];
         foreach ($this->xs as $x) {
             $y    = $f($x);
             if (is_null($y)) continue;
             $ys[] = $y;
         }
         return self::of($ys);
     }

     public function join(string $separator = ''): string
     {
         return implode($separator, $this->xs);
     }
}

class Path
{
    public static function relativeTo(string $base, string $pathname): string
    {
        if (str_starts_with($pathname, $base))
            return substr($pathname, strlen($base));
        else
            return $pathname;
    }
}

class Filesystem
{
    /** @return Arr<string> */
    public static function scandir(string $pathname): Arr
    {
        $children = scandir($pathname);
        if (!$children)
            throw new Exception("Could not scan directory: {$pathname}");
        return Arr::of($children)
                  ->filter(fn($x) => $x !== '.' && $x !== '..')
                  ->map(fn($x) => $pathname . '/' . $x);
    }
}

function parsePathname(string $pathname): Dir|File|null
{
    if (is_dir($pathname))
        return new Dir($pathname);
    else if (is_file($pathname))
        return new File($pathname);
    else
        return null;
}

class Dir
{
    public function __construct(public readonly string $pathname) {}

    /** @return Arr<Dir|File> */
    public function scan(): Arr
    {
        return Filesystem::scandir($this->pathname)
                         ->filterMap(parsePathname(...));
    }
}

class File
{
    public function __construct(public readonly string $pathname) {}

    /** @return iterable<string> */
    public function stream(): iterable
    {
        $resource = fopen($this->pathname, 'r');
        if (!$resource) throw new Exception("Could not read file: {$this->pathname}");
        while (!feof($resource))
        {
            $chunk = fread($resource, 4096);
            if (!$chunk) break;
            yield $chunk;
        }
        fclose($resource);
    }

    public function mimetype(): string
    {
        return mime_content_type($this->pathname) ?: 'application/octet-stream';
    }
}

class Request
{
    public function pathname(): string
    {
        return $_SERVER['REQUEST_URI'];
    }
}

class Response
{
    /**
     * @param array<string, string>   $headers
     * @param string|iterable<string> $body
     */
    public function __construct(
        public readonly int             $status,
        public readonly array           $headers,
        public readonly string|iterable $body,
    ) {}

    public static function badRequest(): self
    {
        return new Response(
            400,
            ['Content-Type' => 'text/plain'],
            'Bad request',
        );
    }
}

class DirectoryView
{
    public static function render(Dir $dir): string
    {
        return $dir->scan()
                   ->map(fn($x) => "<p>{$x->pathname}</p>")
                   ->join("\n");
    }
}

class App
{
    public function __construct(private readonly string $base) {}

    public function run(): void
    {
        $request  = new Request();
        $pathname = $this->base . $request->pathname();
        $entry    = parsePathname($pathname);
        if ($entry instanceof Dir)
            $response = new Response(
                200,
                ['Content-Type' => 'text/html'],
                DirectoryView::render($entry),
            );
        else if ($entry instanceof File)
            $response = new Response(
                200,
                ['Content-Type' => $entry->mimetype()],
                $entry->stream()
            );
        else
            $response = Response::badRequest();
        self::writeResponse($response);
    }

    private static function writeResponse(Response $response): void
    {
        http_response_code($response->status);
        foreach ($response->headers as $k => $v)
            header($k, $v);
        if (is_string($response->body))
            echo $response->body;
        else
        {
            foreach ($response->body as $chunk) echo $chunk;
        }
    }
}

(new App(getcwd()))->run();
