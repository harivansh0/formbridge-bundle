<?php

declare(strict_types=1);

namespace FormBridge\Agent;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The forms an agent filled in while answering one request, for whoever shows the agent's answer.
 */
final class DraftLog implements ResetInterface
{
    /** @var list<DraftedForm> */
    private array $drafts = [];

    public function record(DraftedForm $draft): void
    {
        $this->drafts[] = $draft;
    }

    /**
     * @return list<DraftedForm>
     */
    public function all(): array
    {
        return $this->drafts;
    }

    public function last(): ?DraftedForm
    {
        return [] === $this->drafts ? null : $this->drafts[array_key_last($this->drafts)];
    }

    public function reset(): void
    {
        $this->drafts = [];
    }
}
