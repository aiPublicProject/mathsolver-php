<?php
/** Plain-assertion test runner (zero dev deps): php tests/run.php */
declare(strict_types=1);

require __DIR__ . '/../src/Solver.php';

use MathSolver\Solver;
use MathSolver\SolverError;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    if ($cond) {
        echo "  ok  {$name}\n";
    } else {
        $failures++;
        echo "FAIL  {$name}\n";
    }
}

function threw(string $name, callable $fn, string $errorCode): void
{
    try {
        $fn();
        check($name, false);
    } catch (SolverError $e) {
        check($name, $e->errorCode === $errorCode);
    }
}

// v0.2 protocol fixtures: the model returns program/steps/check — never an answer.
$GOOD = json_encode(['program' => "let d = 11 - 3;\nlet x = d / 2;\nresult = x", 'steps' => ['Subtract 3: 2x = 8', 'Divide by 2: x = 4'], 'check' => '2*{x} + 3 - 11']);
$NO_CHECK = json_encode(['program' => 'result = 0.15 * 80', 'steps' => ['Compute 15% of 80']]);
$WRONG_CHECK = json_encode(['program' => "let d = 11 - 3;\nresult = d / 2", 'steps' => ['...'], 'check' => '2*{x} + 3 - 12']);
$BROKEN_PROGRAM = json_encode(['program' => 'result = undefinedvar + 1', 'steps' => []]);

/* ---- expression evaluator ---- */
check('2*3+4=10', abs(Solver::evalExpression('2*3+4') - 10) < 1e-9);
check('2^3^2=512', abs(Solver::evalExpression('2^3^2') - 512) < 1e-9);
check('-3^2=-9', abs(Solver::evalExpression('-3^2') + 9) < 1e-9);
check('sqrt(16)=4', abs(Solver::evalExpression('sqrt(16)') - 4) < 1e-9);
check('min(3,5)=3', abs(Solver::evalExpression('min(3,5)') - 3) < 1e-9);
check('pi constant', abs(Solver::evalExpression('pi') - M_PI) < 1e-12);
foreach (['system("x")', '1+2)', 'foo(1)', ''] as $bad) {
    threw("rejects '{$bad}'", fn() => Solver::evalExpression($bad), 'EXPR_');
}

/* ---- env variables ---- */
check('env resolves vars', abs(Solver::evalExpression('d / 2', ['d' => 8.0]) - 4) < 1e-9);
check('env resolves two vars', abs(Solver::evalExpression('x + y', ['x' => 1.5, 'y' => 2.5]) - 4) < 1e-9);
threw('undefined var errors', fn() => Solver::evalExpression('d'), 'EXPR_UNKNOWN_ID');
check('env shadows pi', abs(Solver::evalExpression('pi', ['pi' => 3.0]) - 3) < 1e-12);

/* ---- program interpreter ---- */
check('runProgram let+result=4', abs(Solver::runProgram("let d = 11 - 3;\nlet x = d / 2;\nresult = x") - 4) < 1e-9);
check('runProgram semicolons+bare=12', abs(Solver::runProgram('let a = 3; let b = 4; a * b') - 12) < 1e-9);
check('runProgram bare=12', abs(Solver::runProgram('0.15 * 80') - 12) < 1e-9);
threw('runProgram undefined var', fn() => Solver::runProgram('result = undefinedvar + 1'), 'EXPR_');
threw('runProgram empty', fn() => Solver::runProgram(''), 'PROGRAM_EMPTY');
threw('runProgram no result', fn() => Solver::runProgram('let a = 1; let b = 2'), 'PROGRAM_NO_RESULT');

/* ---- check substitution ---- */
$pass = Solver::runCheck('2*{x} + 3 - 11', 4.0);
$fail = Solver::runCheck('2*{x} + 3 - 12', 4.0);
$alt = Solver::runCheck('80*15/100 - {x}', 12.0);
check('runCheck pass', $pass['passed'] === true && abs($pass['value']) < 1e-9);
check('runCheck fail', $fail['passed'] === false && abs($fail['value'] + 1) < 1e-9);
check('runCheck recompute path', $alt['passed'] === true);

/* ---- constructor validation ---- */
threw('NO_API_KEY at construct', fn() => new Solver(''), 'NO_API_KEY');
threw('BAD_BASE_URL at construct', fn() => new Solver('sk', 'not-a-url'), 'BAD_BASE_URL');

/* ---- solve: answer comes from program execution ---- */
$calls = 0;
$seen = [];
$solver = new Solver('sk-test', 'https://api.deepseek.com/v1', 'deepseek-chat',
    function ($url, $body, $key) use (&$calls, &$seen, $GOOD) {
        $calls++;
        $seen = [$url, $body, $key];
        return $GOOD;
    });
$r = $solver->solve('2x + 3 = 11, solve for x');
// 答案=执行产物(4), 代回检验=0; 模型 JSON 里没有 answer 字段
check('verified first try',
    $r['verified'] === true && $r['retries'] === 0 && abs($r['answer'] - 4) < 1e-9
    && $r['checkValue'] !== null && abs($r['checkValue']) < 1e-9 && $calls === 1);
check('url/body/key passed',
    str_ends_with($seen[0], '/chat/completions') && $seen[2] === 'sk-test'
    && $seen[1]['model'] === 'deepseek-chat' && $seen[1]['temperature'] === 0);
check('protocol: no answer field in model JSON', !str_contains($GOOD, '"answer"'));

/* no check provided */
$r = (new Solver('sk', 'https://api.x', 'm', fn() => $GLOBALS['NO_CHECK']))->solve('15% of 80');
check('no check -> unverified, answer from execution',
    abs($r['answer'] - 12) < 1e-9 && $r['verified'] === false && $r['check'] === '' && $r['checkValue'] === null);

/* check fails -> corrective retry -> recovers */
$n = 0;
$r = (new Solver('sk', 'https://api.x', 'm', function () use (&$n) {
    $n++;
    return $n === 1 ? $GLOBALS['WRONG_CHECK'] : $GLOBALS['GOOD'];
}))->solve('2x+3=11');
check('check fail retry recovers', $r['verified'] === true && $r['retries'] === 1 && abs($r['answer'] - 4) < 1e-9);

/* program execution error -> retry -> fixed */
$n = 0;
$r = (new Solver('sk', 'https://api.x', 'm', function () use (&$n) {
    $n++;
    return $n === 1 ? $GLOBALS['BROKEN_PROGRAM'] : $GLOBALS['GOOD'];
}))->solve('2x+3=11');
check('program error retry recovers', $r['verified'] === true && abs($r['answer'] - 4) < 1e-9);

/* program error persists -> PROGRAM_*/EXPR_* thrown */
try {
    (new Solver('sk', 'https://api.x', 'm', fn() => $GLOBALS['BROKEN_PROGRAM']))->solve('2x+3=11');
    check('program error persists throws', false);
} catch (SolverError $e) {
    check('program error persists throws',
        str_starts_with($e->errorCode, 'PROGRAM_') || str_starts_with($e->errorCode, 'EXPR_'));
}

/* invalid JSON then ok / twice raises */
$n = 0;
$r = (new Solver('sk', 'https://api.x', 'm', function () use (&$n) {
    $n++;
    return $n === 1 ? 'no json' : $GLOBALS['GOOD'];
}))->solve('1+1');
check('invalid json then ok', $r['verified'] === true);
threw('invalid twice raises', fn() => (new Solver('sk', 'https://api.x', 'm', fn() => 'nothing'))->solve('1+1'), 'INVALID_JSON');

/* http error no retry */
$calls = 0;
try {
    (new Solver('sk', 'https://api.x', 'm', function () use (&$calls) {
        $calls++;
        throw new SolverError('HTTP_ERROR', '401');
    }))->solve('1+1');
    check('http error no retry', false);
} catch (SolverError $e) {
    check('http error no retry', $e->errorCode === 'HTTP_ERROR' && $calls === 1);
}

/* check still failing after retry -> unverified, answer from execution */
$r = (new Solver('sk', 'https://api.x', 'm', fn() => $GLOBALS['WRONG_CHECK']))->solve('2x+3=11');
check('still failing unverified', abs($r['answer'] - 4) < 1e-9 && $r['verified'] === false && $r['retries'] === 1);

/* ---- smoke: real API (set SMOKE_API_KEY to run; key never touches git) ---- */
$smokeKey = getenv('SMOKE_API_KEY');
if ($smokeKey !== false && $smokeKey !== '') {
    $base = getenv('SMOKE_BASE_URL') ?: 'https://api.openai.com/v1';
    $r = (new Solver($smokeKey, $base))->solve('2x + 3 = 11, solve for x');
    echo 'smoke: answer=' . $r['answer'] . ' verified=' . var_export($r['verified'], true) . ' retries=' . $r['retries'] . "\n";
    if (!($r['verified'] && abs($r['answer'] - 4) < 1e-9)) {
        exit(1);
    }
}

/* ---- HTTP-interface mock (fake below the default transport) ---- */
require __DIR__ . '/http_mock.php';

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURES\n";
exit($failures === 0 ? 0 : 1);
