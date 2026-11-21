<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @internal An event dispatcher wrapper that pins `KernelEvents::RESPONSE` listeners in place.
 *
 * `ContextListener` adds its `onKernelResponse` listener on the first main
 * request and removes it again from that listener, so the first response that
 * ran the firewall unregisters it for everyone. With overlapping requests that
 * drops the listener while another request still needs it. This wrapper forwards
 * the first `addListener(KernelEvents::RESPONSE, …)` and ignores the later ones
 * and every `removeListener(KernelEvents::RESPONSE, …)`, so the listener stays
 * registered once added. Every other call, and every other event, is forwarded.
 *
 * It holds no per-request state, so it needs no scope token.
 */
final class PinnedResponseListenerDispatcher implements EventDispatcherInterface
{
    private bool $responseListenerAdded = false;

    public function __construct(private readonly EventDispatcherInterface $inner) {}

    public function dispatch(object $event, ?string $eventName = null): object
    {
        return $this->inner->dispatch($event, $eventName);
    }

    public function addListener(string $eventName, callable $listener, int $priority = 0): void
    {
        if ($eventName === KernelEvents::RESPONSE) {
            if ($this->responseListenerAdded) {
                return;
            }

            $this->responseListenerAdded = true;
        }

        $this->inner->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->addSubscriber($subscriber);
    }

    public function removeListener(string $eventName, callable $listener): void
    {
        if ($eventName === KernelEvents::RESPONSE) {
            return;
        }

        $this->inner->removeListener($eventName, $listener);
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->removeSubscriber($subscriber);
    }

    public function getListeners(?string $eventName = null): array
    {
        return $this->inner->getListeners($eventName);
    }

    public function getListenerPriority(string $eventName, callable $listener): ?int
    {
        return $this->inner->getListenerPriority($eventName, $listener);
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->inner->hasListeners($eventName);
    }
}
