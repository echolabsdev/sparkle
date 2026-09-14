<?php

declare(strict_types=1);

namespace Prism\Dev\Rectors;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Rector\AbstractRector;

class ReorderMethodsRector extends AbstractRector
{
    #[\Override]
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param  Class_  $node
     */
    #[\Override]
    public function refactor(Node $node): ?Node
    {
        $methods = $node->getMethods();

        if (count($methods) <= 1) {
            return null;
        }

        $reorderedMethods = $this->reorderMethods($methods);

        if ($methods === $reorderedMethods) {
            return null;
        }

        $node->stmts = array_merge(
            array_filter($node->stmts, fn (Stmt $stmt): bool => ! $stmt instanceof ClassMethod),
            $reorderedMethods
        );

        return $node;
    }

    /**
     * @param  array<int, ClassMethod>  $methods
     * @return array<int, ClassMethod>
     */
    protected function reorderMethods(array $methods): array
    {
        usort($methods, fn (ClassMethod $a, ClassMethod $b): int => $this->getMethodWeight($a) <=> $this->getMethodWeight($b));

        return $methods;
    }

    protected function getMethodWeight(ClassMethod $method): int
    {
        if ($this->isMagicMethod($method)) {
            return 0;
        }

        if ($method->isPublic()) {
            return 1;
        }

        if ($method->isProtected()) {
            return 2;
        }

        return 3; // private
    }

    protected function isMagicMethod(ClassMethod $method): bool
    {
        return str_starts_with($method->name->toString(), '__');
    }
}
