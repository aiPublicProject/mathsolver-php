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

$GOOD = json_encode(['answer' => 4, 'steps' => ['Subtract 3: 2x = 8', 'Divide by 2: x = 4'], 'verification' => ['expression' => '(11-3)/2']]);
$WRONG = json_encode(['answer' => 4, 'steps' => ['...'], 'verification' => ['expression' => '(11-3)/3']]);

/* evaluator */
check('2*3+4=10', abs(Solver::evalExpression('2*3+4') - 10) < 1e-9);
check('2^3^2=512', abs(Solver::evalExpression('2^3^2') - 512) < 1e-9);
check('-3^2=-9', abs(Solver::evalExpression('-3^2') + 9) < 1e-9);
check('sqrt(16)=4', abs(Solver::evalExpression('sqrt(16)') - 4) < 1e-9);
check('min(3,5)=3', abs(Solver::evalExpression('min(3,5)') - 3) < 1e-9);
check('pi constant', abs(Solver::evalExpression('pi') - M_PI) < 1e-12);
foreach (['system("x")', '1+2)', 'foo(1)', ''] as $bad) {
    try { Solver::evalExpression($bad); check("rejects '{$bad}'", false); }
    catch (SolverError) { check("rejects '{$bad}'", true); }
}

/* solve: verified first try */
$calls = 0;
$r = Solver::solve('2x + 3 = 11, solve for x', ['apiKey' => 'sk-test', 'transport' =>
    function ($url, $body, $key) use (&$calls, &$seen) {
        $calls++;
        $seen = [$url, $body, $key];
        return json_encode(['choices' => [['message' => ['content' => $GLOBALS['GOOD']]]]]);
    }]);
check('verified first try', $r['verified'] === true && $r['retries'] === 0 && abs($r['evaluated'] - 4) < 1e-9 && $calls === 1);
check('url/body/key passed', str_ends_with($seen[0], '/chat/completions') && $seen[2] === 'sk-test');

/* solve: mismatch then corrected */
$n = 0;
$r = Solver::solve('2x+3=11', ['apiKey' => 'sk', 'transport' => function () use (&$n) {
    $n++;
    $body = $n === 1 ? $GLOBALS['WRONG'] : $GLOBALS['GOOD'];
    return json_encode(['choices' => [['message' => ['content' => $body]]]]);
}]);
check('retry recovers', $r['verified'] === true && $r['retries'] === 1);

/* invalid JSON then ok */
$n = 0;
$r = Solver::solve('1+1', ['apiKey' => 'sk', 'transport' => function () use (&$n) {
    $n++;
    $content = $n === 1 ? 'no json' : $GLOBALS['GOOD'];
    return json_encode(['choices' => [['message' => ['content' => $content]]]]);
}]);
check('invalid json then ok', $r['verified'] === true);

/* invalid twice raises */
try { Solver::solve('1+1', ['apiKey' => 'sk', 'transport' => fn() => 'nothing']); check('invalid twice raises', false); }
catch (SolverError $e) { check('invalid twice raises', $e->code === 'INVALID_JSON'); }

/* no api key */
try { Solver::solve('1+1', []); check('NO_API_KEY', false); }
catch (SolverError $e) { check('NO_API_KEY', $e->code === 'NO_API_KEY'); }

/* http error no retry */
$calls = 0;
try {
    Solver::solve('1+1', ['apiKey' => 'sk', 'transport' => function () use (&$calls) {
        $calls++;
        throw new SolverError('HTTP_ERROR', '401');
    }]);
    check('http error no retry', false);
} catch (SolverError $e) {
    check('http error no retry', $e->code === 'HTTP_ERROR' && $calls === 1);
}

/* retry still wrong => unverified */
$r = Solver::solve('2x+3=11', ['apiKey' => 'sk', 'transport' => fn() => json_encode(['choices' => [['message' => ['content' => $GLOBALS['WRONG']]]]])]);
check('still wrong unverified', $r['verified'] === false && $r['retries'] === 1);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURES\n";
exit($failures === 0 ? 0 : 1);
