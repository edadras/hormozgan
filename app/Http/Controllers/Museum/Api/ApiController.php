<?php

namespace App\Http\Controllers\Museum\Api;

use App\Http\Controllers\Controller;
use App\Museum\Support\CacheVersion;
use App\Museum\Support\EntityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class ApiController extends Controller
{
    public function __construct(protected EntityPresenter $presenter) {}

    protected function perPage(Request $r): int
    {
        return max(1, min((int) $r->integer('per_page', config('museum.api.per_page')), (int) config('museum.api.max_per_page')));
    }

    protected function locale(Request $r): string
    {
        return in_array($r->query('locale'), ['fa', 'en'], true) ? $r->query('locale') : 'fa';
    }

    /** Public GET responses are cached under the versioned museum namespace. */
    protected function cached(Request $r, \Closure $build): JsonResponse
    {
        $query = $r->query();
        ksort($query);
        $key = 'api:'.$r->path().'?'.http_build_query($query);
        $data = CacheVersion::remember(md5($key), (int) config('museum.api.cache_ttl'), $build);

        return response()->json($data)->header('Cache-Control', 'public, max-age=120');
    }

    protected function paginated(LengthAwarePaginator $p, \Closure $map): array
    {
        return [
            'data' => collect($p->items())->map($map)->values(),
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ];
    }
}
