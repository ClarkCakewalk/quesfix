<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Ast\Node;
use Quesfix\StataLogic\Program\StataProgram;

/**
 * 已編譯的檢核邏輯（運算式或程式），可重複對不同樣本求值，
 * 避免每次重新解析。由 LogicEngine::compile() 產生。
 */
final class CompiledLogic
{
    public function __construct(private readonly StataProgram|Node $compiled)
    {
    }

    public function evaluate(VariableResolverInterface $resolver): bool
    {
        if ($this->compiled instanceof StataProgram) {
            return $this->compiled->run($resolver);
        }

        return (new Evaluator($resolver))->evaluateBool($this->compiled);
    }
}
