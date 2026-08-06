<?php

namespace App\Services\Python;

use App\Models\Package;
use App\Models\PythonDist;
use Illuminate\Support\Collection;

/**
 * Renders the PEP 503 (HTML) and PEP 691 (JSON) "simple" repository responses that
 * pip consumes. File links point back at this instance's download endpoint and carry
 * the sha256 hash so pip can verify integrity.
 */
class PythonSimpleIndexBuilder
{
    /**
     * The project detail page. `$baseUrl` is the registry root (…/r/{slug} or the custom
     * domain), used to build absolute file URLs.
     *
     * @param  Collection<int, PythonDist>  $dists
     */
    public function projectHtml(Package $package, Collection $dists, string $baseUrl): string
    {
        $name = e(PythonName::normalize($package->name));
        $links = $dists->map(function (PythonDist $d) use ($package, $baseUrl): string {
            $href = e($this->fileUrl($package, $d, $baseUrl));
            $attrs = '';
            if ($d->requires_python !== null) {
                $attrs .= ' data-requires-python="'.e($d->requires_python).'"';
            }
            if ($d->yanked) {
                $attrs .= ' data-yanked="'.e($d->yanked_reason ?? '').'"';
            }

            return '    <a href="'.$href.'"'.$attrs.'>'.e($d->filename).'</a><br/>';
        })->implode("\n");

        return <<<HTML
<!DOCTYPE html>
<html>
  <head><meta name="pypi:repository-version" content="1.0"><title>Links for {$name}</title></head>
  <body>
    <h1>Links for {$name}</h1>
{$links}
  </body>
</html>

HTML;
    }

    /**
     * PEP 691 JSON representation of the same project detail page.
     *
     * @param  Collection<int, PythonDist>  $dists
     * @return array<string, mixed>
     */
    public function projectJson(Package $package, Collection $dists, string $baseUrl): array
    {
        return [
            'meta' => ['api-version' => '1.0'],
            'name' => PythonName::normalize($package->name),
            'files' => $dists->map(fn (PythonDist $d): array => [
                'filename' => $d->filename,
                'url' => $this->fileUrl($package, $d, $baseUrl),
                'hashes' => ['sha256' => $d->sha256],
                'requires-python' => $d->requires_python,
                'yanked' => $d->yanked ? ($d->yanked_reason ?? true) : false,
            ])->values()->all(),
        ];
    }

    /**
     * The root index listing every project name.
     *
     * @param  Collection<int, string>  $projectNames  already-normalised names
     */
    public function rootHtml(Collection $projectNames, string $baseUrl): string
    {
        $links = $projectNames->unique()->sort()->map(
            fn (string $n): string => '    <a href="'.e($baseUrl).'/simple/'.e($n).'/">'.e($n).'</a><br/>'
        )->implode("\n");

        return <<<HTML
<!DOCTYPE html>
<html>
  <head><meta name="pypi:repository-version" content="1.0"><title>Simple index</title></head>
  <body>
{$links}
  </body>
</html>

HTML;
    }

    private function fileUrl(Package $package, PythonDist $dist, string $baseUrl): string
    {
        return $baseUrl.'/pypi/files/'.$package->id.'/'.$dist->filename.'#sha256='.$dist->sha256;
    }
}
