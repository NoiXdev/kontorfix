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
     * The single source of truth for the Simple API version this builder claims to
     * implement, shared by the JSON `meta.api-version` field and both HTML
     * `pypi:repository-version` meta tags. Raise it only together with the fields the
     * new version requires — see the note on `projectJson()`.
     */
    private const SIMPLE_API_VERSION = '1.4';

    /**
     * The project detail page. `$baseUrl` is the registry root (…/r/{slug} or the custom
     * domain), used to build absolute file URLs.
     *
     * @param  Collection<int, PythonDist>  $dists
     */
    public function projectHtml(Package $package, Collection $dists, string $baseUrl): string
    {
        $name = e(PythonName::normalize($package->name));
        $apiVersion = self::SIMPLE_API_VERSION;
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

        $status = $this->projectStatus($package);
        $statusTags = '<meta name="pypi:project-status" content="'.e($status['status']).'">';
        if (isset($status['reason'])) {
            $statusTags .= '<meta name="pypi:project-status-reason" content="'.e($status['reason']).'">';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
  <head><meta name="pypi:repository-version" content="{$apiVersion}">{$statusTags}<title>Links for {$name}</title></head>
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
            // 1.1 adds the `versions` key and makes `size` mandatory per file; 1.2 (tracks) and
            // 1.3 (provenance) are optional and stay absent, which the specification permits;
            // 1.4 (PEP 792) adds `project-status`, which is always present below. The number is
            // a claim about what this response contains — raise it only together with the
            // fields the version requires.
            'meta' => ['api-version' => self::SIMPLE_API_VERSION],
            'name' => PythonName::normalize($package->name),
            'versions' => $dists->pluck('version')->unique()->values()->all(),
            'files' => $dists->map(fn (PythonDist $d): array => array_filter([
                'filename' => $d->filename,
                'url' => $this->fileUrl($package, $d, $baseUrl),
                'hashes' => ['sha256' => $d->sha256],
                'requires-python' => $d->requires_python,
                'size' => $d->size,
                'upload-time' => $d->uploaded_at?->toIso8601String(),
                'yanked' => $d->yanked ? ($d->yanked_reason ?? true) : false,
            ], fn (mixed $v): bool => $v !== null))->values()->all(),
            'project-status' => $this->projectStatus($package),
        ];
    }

    /**
     * PEP 792 project status. The key inside the object is `status` — the PEP's prose says
     * `state`, but PyPI and the normative PyPA specification both serve `status`, and that is
     * what clients read. We always emit `deprecated` rather than `archived` or `quarantined`:
     * the PEP defines `deprecated` as obsolete and possibly superseded, which is what an
     * abandonment with a replacement is; `archived` means only "no further updates expected"
     * and `quarantined` signals a security incident.
     *
     * @return array{status: string, reason?: string}
     */
    private function projectStatus(Package $package): array
    {
        $notice = $package->abandonmentNotice();

        return $notice === null
            ? ['status' => 'active']
            : ['status' => 'deprecated', 'reason' => $notice->message()];
    }

    /**
     * The root index listing every project name.
     *
     * @param  Collection<int, string>  $projectNames  already-normalised names
     */
    public function rootHtml(Collection $projectNames, string $baseUrl): string
    {
        $apiVersion = self::SIMPLE_API_VERSION;
        $links = $projectNames->unique()->sort()->map(
            fn (string $n): string => '    <a href="'.e($baseUrl).'/simple/'.e($n).'/">'.e($n).'</a><br/>'
        )->implode("\n");

        return <<<HTML
<!DOCTYPE html>
<html>
  <head><meta name="pypi:repository-version" content="{$apiVersion}"><title>Simple index</title></head>
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
