<?php
/**
 * mathsolver-php — BYOK AI math solver with independent verification.
 * An answer is only verified=true when the model's verification expression
 * (pure arithmetic) is evaluated locally and matches the answer.
 */

namespace MathSolver;

class SolverError extends \Exception
{
    public function __construct(public readonly string $code, string $message)
    {
        parent::__construct($message);
    }
}

final class Solver
{
    public const SYSTEM_PROMPT = "You are a precise math solver.\n"
        . "Reply with STRICT JSON only, no markdown fences, in this exact shape:\n"
        . '{"answer": <number>, "steps": [<string>, ...], "verification": {"expression": "<string>"}}' . "\n"
        . "Rules:\n"
        . "- \"answer\" must be a single number (the final result).\n"
        . "- \"steps\" must be an array of short plain-language explanation strings.\n"
        . "- \"verification.expression\" must be a pure arithmetic expression that\n"
        . "  evaluates to the answer. Allowed: numbers, + - * / % ^ ( ), and the\n"
        . "  functions abs sqrt sin cos tan ln log exp floor ceil round min max\n"
        . "  (log is base 10, ln is natural), and the constants pi and e.\n"
        . "- The expression must recompute the answer independently.";

    /**
     * Evaluate a pure arithmetic expression string (no eval()).
     */
    public static function evalExpression(string $src): float
    {
        $src = trim($src);
        if ($src === '') {
            throw new SolverError('EXPR_EMPTY', 'empty expression');
        }
        $tokens = self::tokenize($src);
        $pos = 0;
        $value = self::parseExpr($tokens, $pos);
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

    private static function parseExpr(array $t, int &$p): float
    {
        $v = self::parseTerm($t, $p);
        while (self::peek($t, $p) !== null && in_array($t[$p][0], ['+', '-'], true)) {
            $op = self::eat($t, $p)[0];
            $r = self::parseTerm($t, $p);
            $v = $op === '+' ? $v + $r : $v - $r;
        }
        return $v;
    }

    private static function parseTerm(array $t, int &$p): float
    {
        $v = self::parseUnary($t, $p);
        while (self::peek($t, $p) !== null && in_array($t[$p][0], ['*', '/', '%'], true)) {
            $op = self::eat($t, $p)[0];
            $r = self::parseUnary($t, $p);
            $v = $op === '*' ? $v * $r : ($op === '/' ? $v / $r : fmod($v, $r));
        }
        return $v;
    }

    private static function parseUnary(array $t, int &$p): float
    {
        if (self::peek($t, $p) !== null && $t[$p][0] === '-') {
            self::eat($t, $p);
            return -self::parseUnary($t, $p);
        }
        if (self::peek($t, $p) !== null && $t[$p][0] === '+') {
            self::eat($t, $p);
            return self::parseUnary($t, $p);
        }
        return self::parsePower($t, $p);
    }

    private static function parsePower(array $t, int &$p): float
    {
        $base = self::parseAtom($t, $p);
        if (self::peek($t, $p) !== null && $t[$p][0] === '^') {
            self::eat($t, $p);
            return pow($base, self::parseUnary($t, $p));
        }
        return $base;
    }

    private static function parseAtom(array $t, int &$p): float
    {
        $tok = self::eat($t, $p);
        if ($tok[0] === 'num') {
            return $tok[1];
        }
        if ($tok[0] === 'id') {
            $name = strtolower($tok[1]);
            if (self::peek($t, $p) !== null && $t[$p][0] === '(') {
                self::eat($t, $p);
                $args = [self::parseExpr($t, $p)];
                while (self::peek($t, $p) !== null && $t[$p][0] === ',') {
                    self::eat($t, $p);
                    $args[] = self::parseExpr($t, $p);
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
            $v = self::parseExpr($t, $p);
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

    public function solve(string $problem): array
    {
        $apiKey = $this->apiKey;
        $baseUrl = $this->baseUrl;
        $model = $this->model;
        $transport = $this->transport ?? [self::class, 'defaultTransport'];
        if (trim($problem) === '') {
            throw new SolverError('NO_PROBLEM', 'problem must be non-empty');
        }
        $url = "{$baseUrl}/chat/completions";
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $problem],
        ];
        $call = fn() => $transport($url, ['model' => $model, 'messages' => $messages, 'temperature' => 0], $apiKey);

        try {
            $parsed = self::parseReply($call());
        } catch (SolverError $e) {
            if ($e->code !== 'INVALID_JSON') throw $e;
            $messages[] = ['role' => 'assistant', 'content' => 'invalid JSON'];
            $messages[] = ['role' => 'user', 'content' => 'Your reply was not valid JSON. Reply again with the exact strict JSON shape.'];
            $parsed = self::parseReply($call());
        }

        $evaluate = function (array $p): array {
            try {
                $ev = self::evalExpression($p['expression']);
                return [$ev, self::equal($ev, $p['answer'])];
            } catch (SolverError) {
                return [null, false];
            }
        };

        [$evaluated, $verified] = $evaluate($parsed);
        $retries = 0;
        if (!$verified) {
            $retries = 1;
            $messages[] = ['role' => 'assistant', 'content' => json_encode($parsed)];
            $messages[] = ['role' => 'user', 'content' => sprintf(
                'Your verification expression evaluated to %s, which does not match your answer %s. Re-derive carefully and reply again with the same strict JSON shape.',
                $evaluated ?? 'an error', $parsed['answer']
            )];
            try {
                $second = self::parseReply($call());
                [$ev2, $ok2] = $evaluate($second);
                if ($ev2 !== null) $evaluated = $ev2;
                if ($ok2) { $parsed = $second; $verified = true; }
            } catch (SolverError) {
            }
        }
        return ['answer' => $parsed['answer'], 'steps' => $parsed['steps'], 'expression' => $parsed['expression'],
                'evaluated' => $evaluated, 'verified' => $verified, 'retries' => $retries];
    }

    public static function defaultTransport(string $url, array $body, string $apiKey): string
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
            'content' => json_encode($body),
            'timeout' => 60,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new SolverError('HTTP_ERROR', 'API call failed');
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
        $answer = $data['answer'] ?? null;
        if (is_string($answer)) {
            preg_match('/-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', $answer, $mm);
            $answer = $mm ? (float)$mm[0] : null;
        }
        if (!is_int($answer) && !is_float($answer)) {
            throw new SolverError('INVALID_JSON', 'missing numeric answer');
        }
        $expression = $data['verification']['expression'] ?? null;
        if (!is_string($expression)) {
            throw new SolverError('INVALID_JSON', 'missing verification.expression');
        }
        $steps = array_map('strval', is_array($data['steps'] ?? null) ? $data['steps'] : []);
        return ['answer' => (float)$answer, 'steps' => $steps, 'expression' => $expression];
    }

    private static function equal(float $a, float $b): bool
    {
        return abs($a - $b) <= 1e-6 * max(1.0, abs($a), abs($b));
    }
}
