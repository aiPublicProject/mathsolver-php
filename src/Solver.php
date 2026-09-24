<?php
/**
 * mathsolver-php — BYOK AI math solver with execution-based verification (v0.2).
 *
 * Correctness model (PAL-style): the model never states the answer.
 * It returns a small JavaScript-like PROGRAM; this package executes the
 * program deterministically and the execution output IS the answer.
 * For equations, a CHECK expression ({x} placeholder) must evaluate to 0
 * when the computed answer is substituted back into the original equation.
 */

namespace MathSolver;

class SolverError extends \Exception
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

final class Solver
{
    public const SYSTEM_PROMPT = "You are a precise math solver.\n"
        . "Reply with STRICT JSON only, no markdown fences, in this exact shape:\n"
        . '{"program": "<string>", "steps": [<string>, ...], "check": "<string>"}' . "\n"
        . "Rules:\n"
        . "- \"program\" is a small JavaScript-like program that computes the final answer.\n"
        . "  One statement per line (or ; separated). Allowed statements:\n"
        . "      let NAME = EXPRESSION\n"
        . "      result = EXPRESSION\n"
        . "  EXPRESSIONs may use numbers, + - * / % ^ ( ), the functions\n"
        . "  abs sqrt sin cos tan ln log exp floor ceil round min max\n"
        . "  (log is base 10, ln is natural), the constants pi and e, and any\n"
        . "  variable defined by an earlier let. The value assigned to \"result\"\n"
        . "  is the answer. Never state the answer as a number in text.\n"
        . "- \"steps\" is an array of short plain-language explanation strings.\n"
        . "- \"check\" is a verification expression containing the placeholder {x}.\n"
        . "  After solving, {x} is replaced by the computed answer and the whole\n"
        . "  expression must evaluate to 0.\n"
        . "  For equations, substitute the answer back into the original equation\n"
        . "  (e.g. 2x+3=11 -> \"2*{x}+3-11\").\n"
        . "  For arithmetic, recompute via a different path and subtract the answer\n"
        . "  (e.g. 15% of 80 -> \"80*15/100-{x}\"). Provide \"check\" whenever possible.";

    private static function correctionPrompt(string $reason): string
    {
        return "Your submission failed verification: {$reason}. "
            . 'Re-derive the problem carefully and reply again with the same strict JSON shape.';
    }

    /**
     * Evaluate a pure arithmetic expression string (no eval()).
     * $env maps variable names (case-sensitive, shadow pi/e) to values.
     */
    public static function evalExpression(string $src, ?array $env = null): float
    {
        $src = trim($src);
        if ($src === '') {
            throw new SolverError('EXPR_EMPTY', 'empty expression');
        }
        $tokens = self::tokenize($src);
        $pos = 0;
        $value = self::parseExpr($tokens, $pos, $env);
        if ($pos !== count($tokens)) {
            throw new SolverError('EXPR_TRAILING', 'trailing tokens');
        }
        if (!is_finite($value)) {
            throw new SolverError('EXPR_NON_FINITE', 'non-finite result');
        }
        return $value;
    }

    private static function tokenize(string $src): array
    {
        $tokens = [];
        preg_match_all('/\s*('
            . '\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|\.\d+'
            . '|[a-zA-Z_][a-zA-Z_0-9]*'
            . '|[-+*\/%^(),]'
            . ')/', $src, $m, PREG_SET_ORDER);
        $covered = 0;
        foreach ($m as $match) {
            $t = $match[1];
            $tokens[] = is_numeric($t) ? ['num', (float)$t] : (preg_match('/^[a-zA-Z_]/', $t) ? ['id', $t] : [$t, null]);
            $covered += strlen($match[0]);
        }
        if (trim(substr($src, $covered)) !== '') {
            throw new SolverError('EXPR_BAD_CHAR', 'unexpected character');
        }
        return $tokens;
    }

    private static function peek(array $tokens, int $pos): ?array
    {
        return $tokens[$pos] ?? null;
    }

    private static function eat(array $tokens, int &$pos): array
    {
        if ($pos >= count($tokens)) {
            throw new SolverError('EXPR_SYNTAX', 'expected more tokens');
        }
        return $tokens[$pos++];
    }

    private static function parseExpr(array $t, int &$p, ?array $env): float
    {
        $v = self::parseTerm($t, $p, $env);
        while (self::peek($t, $p) !== null && in_array($t[$p][0], ['+', '-'], true)) {
            $op = self::eat($t, $p)[0];
            $r = self::parseTerm($t, $p, $env);
            $v = $op === '+' ? $v + $r : $v - $r;
        }
        return $v;
    }

    private static function parseTerm(array $t, int &$p, ?array $env): float
    {
        $v = self::parseUnary($t, $p, $env);
        while (self::peek($t, $p) !== null && in_array($t[$p][0], ['*', '/', '%'], true)) {
            $op = self::eat($t, $p)[0];
            $r = self::parseUnary($t, $p, $env);
            $v = $op === '*' ? $v * $r : ($op === '/' ? $v / $r : fmod($v, $r));
        }
        return $v;
    }

    private static function parseUnary(array $t, int &$p, ?array $env): float
    {
        if (self::peek($t, $p) !== null && $t[$p][0] === '-') {
            self::eat($t, $p);
            return -self::parseUnary($t, $p, $env);
        }
        if (self::peek($t, $p) !== null && $t[$p][0] === '+') {
            self::eat($t, $p);
            return self::parseUnary($t, $p, $env);
        }
        return self::parsePower($t, $p, $env);
    }

    private static function parsePower(array $t, int &$p, ?array $env): float
    {
        $base = self::parseAtom($t, $p, $env);
        if (self::peek($t, $p) !== null && $t[$p][0] === '^') {
            self::eat($t, $p);
            return pow($base, self::parseUnary($t, $p, $env));
        }
        return $base;
    }

    private static function parseAtom(array $t, int &$p, ?array $env): float
    {
        $tok = self::eat($t, $p);
        if ($tok[0] === 'num') {
            return $tok[1];
        }
        if ($tok[0] === 'id') {
            $raw = $tok[1];
            if ($env !== null && array_key_exists($raw, $env)) {
                return $env[$raw];
            }
            $name = strtolower($raw);
            if (self::peek($t, $p) !== null && $t[$p][0] === '(') {
                self::eat($t, $p);
                $args = [self::parseExpr($t, $p, $env)];
                while (self::peek($t, $p) !== null && $t[$p][0] === ',') {
                    self::eat($t, $p);
                    $args[] = self::parseExpr($t, $p, $env);
                }
                if (self::eat($t, $p)[0] !== ')') {
                    throw new SolverError('EXPR_SYNTAX', 'expected )');
                }
                return (float)self::applyFn($name, $args);
            }
            if ($name === 'pi') return M_PI;
            if ($name === 'e') return M_E;
            throw new SolverError('EXPR_UNKNOWN_ID', "unknown identifier {$name}");
        }
        if ($tok[0] === '(') {
            $v = self::parseExpr($t, $p, $env);
            if (self::eat($t, $p)[0] !== ')') {
                throw new SolverError('EXPR_SYNTAX', 'expected )');
            }
            return $v;
        }
        throw new SolverError('EXPR_SYNTAX', "unexpected token {$tok[0]}");
    }

    private static function applyFn(string $name, array $args): float
    {
        return match ($name) {
            'abs' => abs($args[0]),
            'sqrt' => sqrt($args[0]),
            'sin' => sin($args[0]),
            'cos' => cos($args[0]),
            'tan' => tan($args[0]),
            'ln' => log($args[0]),
            'log' => log10($args[0]),
            'exp' => exp($args[0]),
            'floor' => floor($args[0]),
            'ceil' => ceil($args[0]),
            'round' => round($args[0]),
            'min' => min($args),
            'max' => max($args),
            default => throw new SolverError('EXPR_UNKNOWN_FUNC', "unknown function {$name}"),
        };
    }

    /* ---------------- program interpreter ---------------- */

    /**
     * Execute a model-generated program. Statements (one per line or ;
     * separated): let NAME = EXPR | NAME = EXPR | bare EXPR. The answer is
     * the value of `result`, else the last bare expression. The model never
     * states the answer as a number — execution output IS the answer.
     */
    public static function runProgram(string $src): float
    {
        if (trim($src) === '') {
            throw new SolverError('PROGRAM_EMPTY', 'empty program');
        }
        $env = [];
        $resultDefined = false;
        $lastDefined = false;
        $lastValue = 0.0;
        $lines = preg_split('/[\n;]+/', $src) ?: [];
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^let\s+([a-zA-Z_]\w*)\s*=\s*(.+)$/s', $line, $m1)) {
                $env[$m1[1]] = self::evalExpression($m1[2], $env);
                if ($m1[1] === 'result') $resultDefined = true;
                continue;
            }
            if (preg_match('/^([a-zA-Z_]\w*)\s*=\s*(.+)$/s', $line, $m2)) {
                $env[$m2[1]] = self::evalExpression($m2[2], $env);
                if ($m2[1] === 'result') $resultDefined = true;
                continue;
            }
            $lastValue = self::evalExpression($line, $env);
            $lastDefined = true;
        }
        if ($resultDefined) {
            return $env['result'];
        }
        if ($lastDefined) {
            return $lastValue;
        }
        throw new SolverError('PROGRAM_NO_RESULT', 'program produced no result');
    }

    /**
     * Substitute the computed answer into a check expression ({x} placeholder)
     * and evaluate it. Returns ['value' => float, 'passed' => bool];
     * passed when the value is ~0 (scaled tolerance).
     */
    public static function runCheck(string $checkSrc, float $answer): array
    {
        $substituted = preg_replace('/\{\s*x\s*\}/i', '(' . json_encode($answer) . ')', $checkSrc);
        $value = self::evalExpression((string)$substituted);
        return [
            'value' => $value,
            'passed' => abs($value) <= 1e-6 * max(1.0, abs($answer)),
        ];
    }

    /**
     * BYOK client for an OpenAI-compatible endpoint. Instantiate once, solve many.
     *
     * $solver = new MathSolver\Solver($apiKey, 'https://api.deepseek.com/v1', 'deepseek-chat');
     * $result = $solver->solve('2x + 3 = 11, solve for x');
     */
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.openai.com/v1',
        private string $model = 'gpt-4o-mini',
        private $transport = null,
    ) {
        if ($this->apiKey === '') {
            throw new SolverError('NO_API_KEY', 'apiKey is required (BYOK)');
        }
        $base = rtrim($this->baseUrl, '/');
        if (!preg_match('#^https?://#', $base)) {
            throw new SolverError('BAD_BASE_URL', 'baseUrl must be an http(s) URL, e.g. https://api.deepseek.com/v1');
        }
        $this->baseUrl = $base;
    }

    /**
     * Test seam for the HTTP interface below the transport: callable
     * (url, headers[], bodyJson) => [status, rawBody]; null = real HTTP.
     */
    public $httpPost = null;

    public function solve(string $problem): array
    {
        $transport = $this->transport
            ?? fn(string $u, array $b, string $k): string => $this->transportVia($u, $b, $k);
        if (trim($problem) === '') {
            throw new SolverError('NO_PROBLEM', 'problem must be non-empty');
        }
        $url = "{$this->baseUrl}/chat/completions";
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $problem],
        ];
        $call = fn() => $transport($url, ['model' => $this->model, 'messages' => $messages, 'temperature' => 0], $this->apiKey);

        try {
            $parsed = self::parseReply($call());
        } catch (SolverError $e) {
            if ($e->errorCode !== 'INVALID_JSON') throw $e;
            $messages[] = ['role' => 'assistant', 'content' => 'invalid JSON'];
            $messages[] = ['role' => 'user', 'content' => 'Your reply was not valid JSON. Reply again with the exact strict JSON shape.'];
            $parsed = self::parseReply($call());
        }

        $attempt = function (array $p): array {
            try {
                $answer = self::runProgram($p['program']);
                $out = ['ok' => true, 'answer' => $answer, 'checkValue' => null, 'verified' => false];
                if ($p['check'] !== '') {
                    $r = self::runCheck($p['check'], $answer);
                    $out['checkValue'] = $r['value'];
                    $out['verified'] = $r['passed'];
                }
                return $out;
            } catch (SolverError $e) {
                return ['ok' => false, 'error' => $e];
            }
        };

        $outcome = $attempt($parsed);
        $retries = 0;
        if (!$outcome['ok'] || !$outcome['verified']) {
            $retries = 1;
            $reason = !$outcome['ok']
                ? sprintf('program failed to execute (%s: %s)', $outcome['error']->errorCode, $outcome['error']->getMessage())
                : sprintf('check evaluated to %s instead of 0', var_export($outcome['checkValue'], true));
            $messages[] = ['role' => 'assistant', 'content' => json_encode([
                'program' => $parsed['program'],
                'steps' => $parsed['steps'],
                'check' => $parsed['check'] === '' ? null : $parsed['check'],
            ])];
            $messages[] = ['role' => 'user', 'content' => self::correctionPrompt($reason)];
            $secondParsed = self::parseReply($call());
            $second = $attempt($secondParsed);
            if (!$second['ok']) {
                throw $second['error']; // PROGRAM_* error persisted after retry
            }
            $parsed = $secondParsed;
            $outcome = $second;
        }
        return ['answer' => $outcome['answer'], 'steps' => $parsed['steps'], 'program' => $parsed['program'],
                'check' => $parsed['check'], 'checkValue' => $outcome['checkValue'],
                'verified' => $outcome['verified'], 'retries' => $retries];
    }

    private function transportVia(string $url, array $body, string $apiKey): string
    {
        $post = $this->httpPost ?? [self::class, 'realHttpPost'];
        [$status, $raw] = $post($url, [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$apiKey}",
        ], (string)json_encode($body));
        return self::contentFromResponse((int)$status, (string)$raw);
    }

    public static function defaultTransport(string $url, array $body, string $apiKey): string
    {
        [$status, $raw] = self::realHttpPost($url, [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$apiKey}",
        ], (string)json_encode($body));
        return self::contentFromResponse($status, $raw);
    }

    /** Real HTTP POST via the stream wrapper. Returns [status, rawBody]. */
    public static function realHttpPost(string $url, array $headers, string $bodyJson): array
    {
        $header = '';
        foreach ($headers as $k => $v) {
            $header .= "{$k}: {$v}\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $header,
            'content' => $bodyJson,
            'timeout' => 60,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new SolverError('HTTP_ERROR', 'API call failed');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        return [$status, (string)$raw];
    }

    private static function contentFromResponse(int $status, string $raw): string
    {
        if ($status >= 300) {
            throw new SolverError('HTTP_ERROR', "API responded {$status}");
        }
        $data = json_decode($raw, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new SolverError('HTTP_ERROR', 'API response missing message content');
        }
        return $content;
    }

    private static function parseReply(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new SolverError('INVALID_JSON', 'no JSON object in reply');
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new SolverError('INVALID_JSON', 'reply was not valid JSON');
        }
        $program = $data['program'] ?? null;
        if (!is_string($program) || trim($program) === '') {
            throw new SolverError('INVALID_JSON', 'missing program');
        }
        $steps = array_map('strval', is_array($data['steps'] ?? null) ? $data['steps'] : []);
        $check = (is_string($data['check'] ?? null) && trim($data['check']) !== '') ? $data['check'] : '';
        return ['program' => $program, 'steps' => $steps, 'check' => $check];
    }
}
