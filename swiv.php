#!/usr/bin/env php
<?php
if (php_sapi_name() === 'cli') {
    $options = getopt('', ['dir:', 'auth:']);
    chdir($options['dir']);
    pcntl_exec(
        "/usr/bin/env",
        ["php", "-S", "localhost:8000", __FILE__],
        ['AUTH' => $options['auth']]
    );
    exit();
}

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

     /** @return Arr<T> */
     public function sortBy(callable $f): Arr
     {
         $copy = [...$this->xs];
         usort($copy, $f);
         return Arr::of($copy);
     }

     /** @return T|null */
     public function at(int $x): mixed
     {
         return array_key_exists($x, $this->xs) ? $this->xs[$x] : null;
     }

     public function length(): int
     {
         return count($this->xs);
     }
}

/**
 * @template T
 * @implements IteratorAggregate<T>
 */
class Iter implements IteratorAggregate
{
    /** @param iterable<T> $xs */
    public function __construct(private iterable $xs) {}

    /**
     * @template X
     * @param Closure(): iterable<X> $f
     * @return Iter<X>
     */
    public static function from(Closure $f): self
    {
        return new Iter($f());
    }

    /** @return Traversable<T> */
    public function getIterator(): Traversable
    {
        foreach ($this->xs as $x) yield $x;
    }

    /**
     * @param callable(T): bool $f
     * @return Iter<T>
     */
    public function filter(callable $f): Iter
    {
        return Iter::from(function() use ($f) {
            foreach ($this->xs as $x)
            {
                if ($f($x)) yield $x;
            }
        });
    }

    /** @return Arr<T> */
    public function materialise(): Arr
    {
        return Arr::of([...$this->xs]);
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

    public static function join(string $a, string $b): string
    {
        if (str_ends_with($a, '/') || str_starts_with($b, '/'))
            return $a . $b;
        else
            return $a . '/' . $b;
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
                  ->map(fn($x) => Path::join($pathname, $x));
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

    public function basename(): string
    {
        return basename($this->pathname);
    }

    /** @return Arr<Dir|File> */
    public function scan(): Arr
    {
        return Filesystem::scandir($this->pathname)
                         ->filterMap(parsePathname(...));
    }

    public function walk(): iterable
    {
        $entries = $this->scan();
        foreach ($entries as $entry)
        {
            if ($entry instanceof Dir)
                yield from $entry->walk();
            else
                yield $entry;
        }
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

function alphabetically(Dir | File $a, Dir | File $b): int
{
    return strcmp($a->pathname, $b->pathname);
}

class Request
{
    public function pathname(): string
    {
        return urldecode(strtok($_SERVER["REQUEST_URI"], '?'));
    }

    public function get(string $key): string
    {
        return $_GET[$key] ?: '';
    }

    public function header(string $key): string
    {
        return $_SERVER['HTTP_' . $key] ?: '';
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
}

interface Respondable
{
    public function getResponse(): Response;
}

class BadRequest extends Error implements Respondable
{
    public function getResponse(): Response
    {
        return new Response(
            400,
            ['Content-Type' => 'text/plain'],
            'Bad request',
        );
    }
}

class Unauthorised extends Error implements Respondable
{
    public function getResponse(): Response
    {
        return new Response(
            401,
            [
                'Content-Type' => 'text/plain',
                'WWW-Authenticate' => 'Basic realm="swiv"',
            ],
            'Unauthorized',
        );
    }
}

class GalleryView
{
    public function __construct(private string $base) {}

    public function render(Dir $dir): string
    {
        return $this->layout($this->navbar() . $this->contents($dir));
    }

    private function contents(Dir $dir): string
    {
        return $dir->scan()->map($this->renderEntry(...))->join('');
    }

    private function navbar(): string
    {
        return <<<EOF
            <nav>
                <a href="?mode=viewer">👁</a>
            </nav>
        EOF;
    }

    private function stylesheet() {
        return <<<EOD
            html {
                background: black;
                color: white;
                overflow: hidden;
                font-family: sans;
            }
            body {
                margin: 0;
                display: flex;
                flex-direction: column;
                flex-wrap: wrap;
                height: 100vh;
                overflow-x: scroll;
                scrollbar-width: none;
            }
            article {
                overflow-wrap: anywhere;
                height: 25vh;
                width: 25vh;
                position: relative;
                overflow: hidden;
            }
            a {
                color: inherit;
                text-decoration: inherit;
                cursor: pointer;
            }
            article img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            article label {
                position: absolute;
                left: 0;
                right: 0;
                bottom: 0;
                padding: 0.5em;
                background: #0008;
            }
            nav {
                position: absolute;
                bottom: 1em;
                right: 1em;
                background: orange;
                z-index: 1000;
                border-radius: 0.5em;
            }
            nav a {
                display: block;
                padding: 1em;
            }
        EOD;
    }

    private function layout(string $body): string
    {
        $style = $this->stylesheet();
        return <<<EOD
            <html>
                <head>
                    <meta charset='utf-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1' />
                    <title>swiv</title>
                    <style>{$style}</style>
                </head>
                <body>{$body}</body>
            </html>
        EOD;
    }

    private function renderEntry(Dir | File $entry): string
    {
        return match ($entry::class) {
            Dir::class => $this->renderDir($entry),
            File::class => $this->renderFile($entry),
            default => '',
        };
    }

    private function renderDir(Dir $dir): string
    {
        /** @var Arr<File> */
        $files = (new Iter($dir->walk()))
            ->filter(fn($x) => $x instanceof File)
            ->materialise()
            ->sortBy(alphabetically(...));
        $firstFile = $files->at(0);
        if (is_null($firstFile)) return '';
        $count = $files->length();
        $label = "{$dir->basename()} ({$count})";
        $src = Path::relativeTo($this->base, $firstFile->pathname);
        $href = Path::relativeTo($this->base, $dir->pathname);
        return <<<EOF
            <article>
                <a href="{$href}">
                    <img src="{$src}" loading='lazy'>
                    <label>{$label}</label>
                </a>
            </article>
        EOF;
    }

    private function renderFile(File $file): string
    {
        $src = Path::relativeTo($this->base, $file->pathname);
        return <<<EOF
            <article>
                <img src="{$src}" loading='lazy'>
            </article>
        EOF;
    }
}

class ImageView
{
    public function __construct(private string $base) {}

    public function render(Dir $dir): string
    {
        $body = (new Iter($dir->walk()))
            ->filter(fn($x) => $x instanceof File)
            ->materialise()
            ->sortBy(alphabetically(...))
            ->map($this->renderEntry(...))
            ->join('');
        return $this->layout($body);
    }

    private function stylesheet() {
        return <<<EOD
            html {
                background: black;
                color: white;
                overflow: hidden;
            }
            body {
                margin: 0;
                display: flex;
                height: 100vh;
                overflow-x: scroll;
                scrollbar-width: none;
                scroll-snap-type: x proximity;
                max-width: fit-content;
            }
            img {
                height: 100vh;
                width: 100vw;
                object-fit: contain;
                scroll-snap-align: center;
            }
        EOD;
    }

    private function layout(string $body): string
    {
        $style = $this->stylesheet();
        return <<<EOD
            <html>
                <head>
                    <meta charset='utf-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1' />
                    <title>swiv</title>
                    <style>{$style}</style>
                </head>
                <body>{$body}</body>
            </html>
        EOD;
    }

    private function renderEntry(File $file): string
    {
        $src = Path::relativeTo($this->base, $file->pathname);
        return <<<EOF
            <img src="{$src}" loading='lazy'>
        EOF;
    }
}

class Router {
    public function __construct(private string $base) {}

    public function route(Request $request) {
        try {
            $this->authenticate($request);
            $pathname = $this->base . $request->pathname();
            $entry    = parsePathname($pathname);
            if ($entry instanceof Dir) {
                $mode = $request->get('mode');
                $view = $mode === 'viewer' ? new ImageView($this->base) : new GalleryView($this->base);
                return new Response(
                    200,
                    ['Content-Type' => 'text/html'],
                    $view->render($entry)
                );
            }
            else if ($entry instanceof File)
                return new Response(
                    200,
                    ['Content-Type' => $entry->mimetype()],
                    $entry->stream()
                );
            else
                throw new BadRequest();
        } catch (Respondable $error) {
            return $error->getResponse();
        } catch (Throwable $error) {
            error_log($error->getMessage());
            return new Response(500, ['Content-Type' => 'text/plain'], 'Internal server error');
        }
    }

    private function authenticate(Request $request): void {
        $credentials = getenv('AUTH') ?: '';
        if (!$credentials) return;
        $target = 'Basic ' . base64_encode($credentials);
        $actual = $request->header('AUTHORIZATION');
        if ($actual !== $target)
            throw new Unauthorised();
    }
}

class App
{
    private readonly Router $router;

    public function __construct(private readonly string $base) {
        $this->router = new Router($base);
    }

    public function run(): void
    {
        $request  = new Request();
        $response = $this->router->route($request);
        self::writeResponse($response);
    }

    private static function writeResponse(Response $response): void
    {
        http_response_code($response->status);
        foreach ($response->headers as $k => $v)
            header("{$k}: {$v}");
        if (is_string($response->body))
            echo $response->body;
        else
        {
            foreach ($response->body as $chunk) echo $chunk;
        }
    }
}

(new App(getcwd()))->run();
