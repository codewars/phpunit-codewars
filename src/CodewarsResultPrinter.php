<?php
namespace Codewars\PHPUnitCodewars;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExceptionWrapper;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestFailure;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Framework\Warning;
use PHPUnit\TextUI\DefaultResultPrinter;
use PHPUnit\Util\Filter;
use SebastianBergmann\Comparator\ComparisonFailure;

/**
 * Outputs events in Codewars format. Based on TeamCity class.
 */
class CodewarsResultPrinter extends DefaultResultPrinter
{
	
	private $prettifier;
    /**
     * @var TestSuite
     */
    private $wrapperSuite = null;
    // Temporarily store failure messages so that the outputs can be written before them.
    private $failures = array();

	public function __construct() {
		parent::__construct();
		 $this->prettifier = new \PHPUnit\Util\TestDox\NamePrettifier();
	}

    /**
     * An error occurred.
     */
    public function addError(Test $test, \Throwable $t, float $time): void
    {
        $this->failures[] = sprintf("\n<ERROR::>%s\n", self::getMessage($t));
        $this->failures[] = sprintf("\n<LOG::-Stacktrace>%s\n", self::escapeLF(self::getDetails($t)));
    }

    /**
     * A warning occurred.
     */
    public function addWarning(Test $test, Warning $e, float $time): void
    {
        $this->failures[] = sprintf("\n<ERROR::>%s\n", self::getMessage($e));
        $this->failures[] = sprintf("\n<LOG::-Stacktrace>%s\n", self::escapeLF(self::getDetails($e)));
    }

    /**
     * A failure occurred.
     */
    public function addFailure(Test $test, AssertionFailedError $e, float $time): void
    {
        $msg = self::getMessage($e);
        if ($e instanceof ExpectationFailedException) {
            $msg .= self::getAssertionDetails($e);
        }
        $this->failures[] = sprintf("\n<FAILED::>%s\n", self::escapeLF($msg));
    }

    /**
     * Incomplete test.
     */
    public function addIncompleteTest(Test $test, \Throwable $t, float $time): void
    {
        $this->write("\n<LOG::>Test Incomplete\n");
    }

    /**
     * Risky test.
     */
    public function addRiskyTest(Test $test, \Throwable $t, float $time): void
    {
        $this->addError($test, $t, $time);
    }

    /**
     * Skipped test.
     */
    public function addSkippedTest(Test $test, \Throwable $t, float $time): void
    {
        $this->write("\n<LOG::>Test Ignored\n");
    }

    /**
     * A testsuite started.
     */
    public function startTestSuite(TestSuite $suite): void
    {
        // The first suite is just a wrapper around the actual suites.
        // Remember this so that this can be used again in `endTestSuite`.
        if ($this->wrapperSuite == null) {
            $this->wrapperSuite = $suite;
            return;
        }

        $suiteName = $suite->getName();
        if (empty($suiteName)) {
            return;
        }
        $suiteName = $this->prettifier->prettifyTestClass($suiteName);
        $this->write(sprintf("\n<DESCRIBE::>%s\n", $suiteName));
    }

    /**
     * A testsuite ended.
     */
    public function endTestSuite(TestSuite $suite): void
    {
        if ($this->wrapperSuite == $suite) {
            $this->wrapperSuite = null;
            return;
        }

        if (empty($suite->getName())) {
            return;
        }

        $this->write("\n<COMPLETEDIN::>\n");
    }

    /**
     * A test started.
     */
    public function startTest(Test $test): void
    {
        $title = $test->getName();
        if ($test instanceof TestCase) {
            $title = $this->prettifier->prettifyTestCase($test);
        }
        $this->write(sprintf("\n<IT::>%s\n", $title));
        $this->failures = array();
    }

    /**
     * A test ended.
     */
    public function endTest(Test $test, float $time): void
    {
        if (\method_exists($test, 'hasOutput') && \method_exists($test, 'getActualOutput')) {
            if ($test->hasOutput()) {
                $this->write($test->getActualOutput());
            }
        }

        if (empty($this->failures)) {
            $this->write("\n<PASSED::>Test Passed\n");
        } else {
            $this->write(join("\n", $this->failures));
        }
        $this->write(sprintf("\n<COMPLETEDIN::>%.4f\n", $time * 1000));
    }

    public function printResult(TestResult $result): void
    {
    }

    private static function getMessage(\Throwable $t): string
    {
        $message = '';

        if ($t instanceof ExceptionWrapper) {
            if ($t->getClassName() !== '') {
                $message .= $t->getClassName();
            }

            if ($message !== '' && $t->getMessage() !== '') {
                $message .= ' : ';
            }
        }

        return $message . $t->getMessage();
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function getDetails(\Throwable $t): string
    {
        $stackTrace = Filter::getFilteredStacktrace($t);
        $previous   = $t instanceof ExceptionWrapper ? $t->getPreviousWrapped() : $t->getPrevious();

        while ($previous) {
            $stackTrace .= "\nCaused by\n" .
                TestFailure::exceptionToString($previous) . "\n" .
                Filter::getFilteredStacktrace($previous);

            $previous = $previous instanceof ExceptionWrapper ?
                $previous->getPreviousWrapped() : $previous->getPrevious();
        }

        return ' ' . \str_replace("\n", "\n ", $stackTrace);
    }

    private static function getPrimitiveValueAsString($value): ?string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value === true ? 'true' : 'false';
        }

        if (\is_scalar($value)) {
            return \print_r($value, true);
        }

        return null;
    }

    private static function escapeLF(string $text): string
    {
        return \str_replace("\n", "<:LF:>", $text);
    }

    private static function getAssertionDetails(ExpectationFailedException $e): string
    {
        $comparisonFailure = $e->getComparisonFailure();
        if (!($comparisonFailure instanceof ComparisonFailure)) {
            return "";
        }

        $expectedString = $comparisonFailure->getExpectedAsString();
        if ($expectedString === null || empty($expectedString)) {
            $expectedString = self::getPrimitiveValueAsString($comparisonFailure->getExpected());
        }

        $actualString = $comparisonFailure->getActualAsString();
        if ($actualString === null || empty($actualString)) {
            $actualString = self::getPrimitiveValueAsString($comparisonFailure->getActual());
        }

        if ($actualString !== null && $expectedString !== null) {
            return sprintf("\nExpected: %s\nActual  : %s", $expectedString, $actualString);
        }

        return "";
    }
}
