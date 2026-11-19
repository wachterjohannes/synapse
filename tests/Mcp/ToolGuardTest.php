<?php

namespace App\Tests\Mcp;

use App\Ai\ToolError;
use App\Mcp\ToolGuard;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ToolGuardTest extends TestCase
{
    public function testAReadOnlyToolPassesWithoutAnyRole(): void
    {
        $guard = $this->guard('text', granted: false);

        self::assertSame('text', $guard->handle($this->tool(new ToolAnnotations(readOnlyHint: true)), []));
    }

    public function testAToolWithoutAnnotationsNeedsTheWriteRole(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->expects(self::never())->method('handle');
        $guard = new ToolGuard($inner, $this->checker(false));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('may only read');
        $guard->handle($this->tool(null), []);
    }

    public function testAToolThatIsExplicitlyNotReadOnlyNeedsTheWriteRole(): void
    {
        $this->expectException(ToolCallException::class);

        $this->guard('x', granted: false)->handle($this->tool(new ToolAnnotations(readOnlyHint: false)), []);
    }

    public function testAWriterMayCallAWritingTool(): void
    {
        self::assertSame('saved', $this->guard('saved', granted: true)->handle($this->tool(null), ['a' => 1]));
    }

    public function testTheGuardAsksForTheWriteRole(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('ROLE_BRAIN_WRITE')->willReturn(true);

        (new ToolGuard($this->inner('x'), $checker))->handle($this->tool(null), []);
    }

    public function testAToolErrorBecomesAToolCallException(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The note "x" already exists.');

        $this->guard(new ToolError('The note "x" already exists.'), granted: true)->handle($this->tool(null), []);
    }

    public function testATextStartingWithErrorIsNotAnError(): void
    {
        $text = "Error: this is how my note starts\n\nand goes on.";

        self::assertSame($text, $this->guard($text, granted: true)->handle($this->tool(new ToolAnnotations(readOnlyHint: true)), []));
    }

    public function testAnElementThatIsNotAToolIsNotChecked(): void
    {
        $reference = new ElementReference(static fn (): string => 'x');
        $error = new ToolError('kept as it is');
        $guard = $this->guard($error, granted: false);

        self::assertSame($error, $guard->handle($reference, []));
    }

    private function guard(mixed $result, bool $granted): ToolGuard
    {
        return new ToolGuard($this->inner($result), $this->checker($granted));
    }

    private function inner(mixed $result): ReferenceHandlerInterface
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn($result);

        return $inner;
    }

    private function checker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }

    private function tool(?ToolAnnotations $annotations): ToolReference
    {
        return new ToolReference(new Tool('t', null, ['type' => 'object', 'properties' => []], null, $annotations), static fn (): string => 'x');
    }
}
