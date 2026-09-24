<?php
/**
 * HTTP-interface mock (no server, no sockets): swaps the solver's httpPost
 * seam so the DEFAULT transport runs its real code path (URL building,
 * headers, body serialization, status handling, response envelope parsing)
 * against synthetic OpenAI-shaped responses. Included by run.php; shares its
 * check()/fixtures. Runs identically in local dev and GitHub CI.
 */
declare(strict_types=1);

use MathSolver\Solver;
use MathSolver\SolverError;

/**
 * Returns a solver whose real HTTP layer is faked. $contents = scripted model
 * reply texts in order; $statuses[i] > 0 replies with that HTTP status.
 * Records each request into $calls.
 */
function mockedSolver(string $apiKey, array $contents, array $statuses, array &$calls): Solver
{
    $n = 0;
    $solver = new Solver($apiKey, 'https://mock.test/v1', 'mock-model');
    $solver->httpPost = function (string $url, array $headers, string $body) use (&$n, &$calls, $contents, $statuses) {
        $i = $n++;
        $calls[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
        $content = $contents[$i] ?? $GLOBALS['GOOD'];
        $status = $statuses[$i] ?? 0;
        if ($status >= 300) {
            return [$status, 'upstream boom'];
        }
        return [200, json_encode(['choices' => [['message' => ['content' => $content]]]])];
    };
    return $solver;
}

/* full round trip through the default transport */
$calls = [];
$r = mockedSolver('sk-mock', [$GOOD], [], $calls)->solve('2x + 3 = 11, solve for x');
check('http mock: round trip verified',
    abs($r['answer'] - 4) < 1e-9 && $r['verified'] === true && $r['retries'] === 0);
check('http mock: one call', count($calls) === 1);
check('http mock: url joined', $calls[0]['url'] === 'https://mock.test/v1/chat/completions');
check('http mock: bearer auth', ($calls[0]['headers']['Authorization'] ?? '') === 'Bearer sk-mock');
check('http mock: model + temperature',
    ($calls[0]['body']['model'] ?? '') === 'mock-model' && ($calls[0]['body']['temperature'] ?? -1) === 0);
$first = $calls[0]['body']['messages'][0] ?? [];
check('http mock: system prompt shape',
    ($first['role'] ?? '') === 'system' && str_contains($first['content'] ?? '', 'STRICT JSON'));

/* check fails -> corrective retry carries the failure reason */
$calls = [];
$r = mockedSolver('sk', [$WRONG_CHECK, $GOOD], [], $calls)->solve('2x+3=11');
check('http mock: retry recovers', $r['verified'] === true && $r['retries'] === 1 && count($calls) === 2);
$retryHasReason = false;
foreach ($calls[1]['body']['messages'] ?? [] as $msg) {
    if (str_contains((string)($msg['content'] ?? ''), 'failed verification')) {
        $retryHasReason = true;
    }
}
check('http mock: retry mentions failed verification', $retryHasReason);

/* invalid JSON -> re-ask -> ok */
$calls = [];
$r = mockedSolver('sk', ['certainly not json', $GOOD], [], $calls)->solve('1+1');
check('http mock: invalid json re-ask', $r['verified'] === true && count($calls) === 2);

/* 500 -> HTTP_ERROR, no retry */
$calls = [];
try {
    mockedSolver('sk', [], [500], $calls)->solve('1+1');
    check('http mock: 500 -> HTTP_ERROR no retry', false);
} catch (SolverError $e) {
    check('http mock: 500 -> HTTP_ERROR no retry',
        $e->errorCode === 'HTTP_ERROR' && count($calls) === 1);
}

/* 401 -> HTTP_ERROR */
$calls = [];
try {
    mockedSolver('sk-bad', [], [401], $calls)->solve('1+1');
    check('http mock: 401 -> HTTP_ERROR', false);
} catch (SolverError $e) {
    check('http mock: 401 -> HTTP_ERROR', $e->errorCode === 'HTTP_ERROR');
}
