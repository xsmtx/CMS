<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Api\StaffApiScope;
use App\Domain\Mcp\McpTool;
use App\Http\Controllers\Controller;
use App\Http\Mcp\McpTools;
use App\Support\Errors\ForbiddenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The MCP endpoint (ADR 0051).
 *
 * JSON-RPC 2.0, three methods, written here rather than pulled in: the subset
 * is small, and a dependency that speaks a protocol on this platform's behalf
 * is a dependency that decides what the platform exposes.
 *
 * It authenticates with a **staff API token** — the one ADR 0049 defined,
 * scoped, expiring, attached to a device and revocable. There is no
 * MCP-specific credential and no second lifetime to get wrong.
 *
 * **Errors come back inside the JSON-RPC envelope, not as an HTTP status.**
 * That is the protocol's own rule and it matters here: a client that got a
 * 403 would report "the server is broken" where the truth is "this token may
 * not read tickets", and the operator would go looking in the wrong place.
 * Authentication is the exception — a request with no valid token never
 * reaches this class, because that failure is about the connection rather
 * than about a call.
 */
final class McpController extends Controller
{
    /**
     * The revision of the protocol this speaks.
     *
     * Stated rather than echoed back: answering with whatever a client asked
     * for would be claiming to support a revision nobody here has read.
     */
    private const string Protocol = '2024-11-05';

    public function __invoke(Request $request, McpTools $tools): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $request->json()->all();

        $id = $payload['id'] ?? null;
        $method = is_string($payload['method'] ?? null) ? $payload['method'] : '';

        /** @var array<string, mixed> $params */
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        // A notification has no id and takes no answer. `initialized` is the
        // one every client sends, and replying to it is a protocol error.
        if ($id === null) {
            return response()->json([], 202);
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::Protocol,
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'infracms', 'version' => (string) config('platform.version', '1.0')],
            ]),
            'tools/list' => $this->result($id, ['tools' => $this->catalogue($request)]),
            'tools/call' => $this->call($id, $params, $tools, $request),
            // -32601 is the protocol's own code for a method that is not
            // here, and a client knows what to do with it.
            default => $this->error($id, -32601, (string) __('mcp.errors.unknown_method')),
        };
    }

    /**
     * What this token may ask.
     *
     * **Narrowed to the scopes the token carries**, rather than listed in full
     * and refused on use. A catalogue that advertised a tool the caller cannot
     * run is a model spending a turn discovering that — and the registry rule
     * core already follows for adapters: a capability the row has not enabled
     * is absent, so a screen cannot offer a button the platform would refuse.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogue(Request $request): array
    {
        $granted = $this->scopes($request);

        $tools = array_filter(
            McpTool::cases(),
            static fn (McpTool $tool): bool => in_array($tool->scope(), $granted, strict: true),
        );

        return array_values(array_map(
            static fn (McpTool $tool): array => [
                'name' => $tool->value,
                // The only thing a model reads when deciding whether this
                // answers the question in front of it.
                'description' => (string) __($tool->descriptionKey()),
                'inputSchema' => $tool->schema(),
            ],
            $tools,
        ));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function call(mixed $id, array $params, McpTools $tools, Request $request): JsonResponse
    {
        $tool = McpTool::tryFrom(is_string($params['name'] ?? null) ? $params['name'] : '');

        if (! $tool instanceof McpTool) {
            return $this->error($id, -32602, (string) __('mcp.errors.unknown_tool'));
        }

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $data = $tools->run($tool, $arguments, $this->scopes($request), $request);
        } catch (ForbiddenException $refusal) {
            /*
             * `isError` inside a successful envelope rather than a JSON-RPC
             * error, which is what the protocol asks for: a tool refusing is
             * something the model should read and reason about, where a
             * transport error is something the client should handle. Telling
             * a model "you may not read tickets" lets it say so; a transport
             * error makes it look like the server fell over.
             */
            return $this->result($id, $this->content($refusal->getMessage(), isError: true));
        } catch (Throwable) {
            return $this->result($id, $this->content((string) __('mcp.errors.failed'), isError: true));
        }

        if ($data === null) {
            return $this->result($id, $this->content((string) __('mcp.errors.not_found')));
        }

        return $this->result($id, $this->content(
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $text, bool $isError = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    /**
     * @return list<StaffApiScope>
     */
    private function scopes(Request $request): array
    {
        /** @var list<StaffApiScope> $granted */
        $granted = $request->attributes->get('api_staff_scopes', []);

        return $granted;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function result(mixed $id, array $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
