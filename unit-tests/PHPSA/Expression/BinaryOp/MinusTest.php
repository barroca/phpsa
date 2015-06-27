<?php

namespace Tests\PHPSA\Expression\BinaryOp;

use PhpParser\Node;
use PHPSA\CompiledExpression;
use PHPSA\Visitor\Expression;

class MinusTest extends \Tests\PHPSA\TestCase
{
    /**
     * Data provider for Minus {int} - {int} = {int}
     *
     * @return array
     */
    public function testIntToIntDataProvider()
    {
        return array(
            array(-1, -1, 0),
            array(-1, 0, -1),
            array(0, -1, 1),
            array(-1, 2, -3),
            array(2, -1, 3),
            array(0, 0, 0),
            array(0, 1, -1),
            array(1, 0, 1),
            array(1, 2, -1),
            array(2, 1, 1),
            array(25, 25, 0),
            array(50, 25, 25),
            array(50, -25, 75),
            array(50, 50, 0),
            array(50, -50, 100),
            array(-50, -50, 0),
        );
    }

    /**
     * Tests {int} - {int} = {int}
     *
     * @dataProvider testIntToIntDataProvider
     */
    public function testMinusIntFromInt($a, $b, $c)
    {
        $baseExpression = new Node\Expr\BinaryOp\Minus(
            new Node\Scalar\LNumber($a),
            new Node\Scalar\LNumber($b)
        );
        $compiledExpression = $this->compileExpression($baseExpression);

        $this->assertInstanceOf('PHPSA\CompiledExpression', $compiledExpression);
        $this->assertSame(CompiledExpression::LNUMBER, $compiledExpression->getType());
        $this->assertSame($c, $compiledExpression->getValue());
    }
}
