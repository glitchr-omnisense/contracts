<?php

namespace Omnisense\Contracts\Policy;

/**
 * What every request must carry: a key, a User-Agent - one that names the
 * application, its version and a contact when the pattern says so
 * (Transitous': "MySite/1.0 (https://example.org; travel@example.org)").
 */
final readonly class Requirements
{
    /** A User-Agent with a product/version and a contact (a URL or an address) in it. */
    public const USER_AGENT_WITH_CONTACT = '~^\S+/\S+.*(?:https?://|@)~';

    /** @param ?string $userAgentPattern a PCRE the User-Agent must match */
    public function __construct(
        public bool $key = false,
        public bool $userAgent = false,
        public ?string $userAgentPattern = null,
    ) {
        if (null !== $userAgentPattern && false === @preg_match($userAgentPattern, '')) {
            throw InvalidPolicyException::field('requirements.userAgent.pattern', 'a regular expression', $userAgentPattern);
        }
    }

    /** Whether a User-Agent meets them: given when one is required, matching the pattern when there is one. */
    public function acceptsUserAgent(?string $userAgent): bool
    {
        if (null === $userAgent || '' === trim($userAgent)) {
            return !$this->userAgent;
        }

        return null === $this->userAgentPattern || 1 === preg_match($this->userAgentPattern, $userAgent);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        Read::only($data, ['key', 'userAgent'], 'requirements');
        $agent = Read::object($data['userAgent'] ?? null, 'requirements.userAgent');
        Read::only($agent, ['required', 'pattern'], 'requirements.userAgent');

        return new self(
            Read::bool($data['key'] ?? null, 'requirements.key'),
            Read::bool($agent['required'] ?? null, 'requirements.userAgent.required'),
            Read::string($agent['pattern'] ?? null, 'requirements.userAgent.pattern'),
        );
    }

    /** @return array{key: bool, userAgent: array{required: bool, pattern: ?string}} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'userAgent' => ['required' => $this->userAgent, 'pattern' => $this->userAgentPattern]];
    }
}
