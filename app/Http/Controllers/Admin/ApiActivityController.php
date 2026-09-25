<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Api\Models\ApiRequestRecord;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the integrations have been doing.
 *
 * Opens on everything rather than on the failures, unlike the operations
 * screen: an operator comes here to answer "is this client hammering us"
 * as often as "why is this client getting a 403", and the second question
 * has a filter.
 *
 * Nothing here is a request body. What an operator needs is who, what,
 * when and what came back; the correlation id leads to the rest of the
 * story in the logs.
 */
final class ApiActivityController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request): Response
    {
        if (! $this->actor->can('platform.audit.view')) {
            throw new ForbiddenException(__('api.errors.forbidden'));
        }

        $refusedOnly = $request->boolean('refused');

        $records = ApiRequestRecord::query()
            ->when($refusedOnly, fn ($query) => $query->where('status', '>=', 400))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Api/Activity', [
            'requests' => [
                'data' => array_values(array_map(
                    static fn (ApiRequestRecord $record): array => [
                        'id' => $record->id,
                        'token' => $record->token_name,
                        'method' => $record->method,
                        'path' => $record->path,
                        'route' => $record->route,
                        'status' => $record->status,
                        'errorCode' => $record->error_code,
                        'durationMs' => $record->duration_ms,
                        'ip' => $record->ip,
                        'correlationId' => $record->correlation_id,
                        'createdAt' => $record->created_at?->toIso8601String(),
                    ],
                    $records->items(),
                )),
                'currentPage' => $records->currentPage(),
                'lastPage' => $records->lastPage(),
                'total' => $records->total(),
                'links' => $records->linkCollection()->all(),
            ],
            'filters' => ['refused' => $refusedOnly],
        ]);
    }
}
