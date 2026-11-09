<?php

namespace Omnisense\Contracts\Policy;

/**
 * What the terms ask to be credited, and where: "display" (beside the data
 * shown), "map" (on a map drawn from it), "credits" (an about or credits
 * page), "source" (the application's source code or documentation).
 */
final readonly class Attribution
{
    public const WHERE = ['display', 'map', 'credits', 'source'];

    /** @var list<string> */
    public array $where;

    /** @param list<string> $where */
    public function __construct(
        public bool $required = false,
        public ?string $text = null,
        public ?string $url = null,
        array $where = [],
    ) {
        foreach ($where as $place) {
            if (!\in_array($place, self::WHERE, true)) {
                throw InvalidPolicyException::field('rights.attribution.where', 'one of "'.implode('", "', self::WHERE).'"', $place);
            }
        }
        $where = array_values(array_unique($where));
        sort($where);
        $this->where = $where;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        Read::only($data, ['required', 'text', 'url', 'where'], 'rights.attribution');
        return new self(
            Read::bool($data['required'] ?? null, 'rights.attribution.required'),
            Read::string($data['text'] ?? null, 'rights.attribution.text'),
            Read::string($data['url'] ?? null, 'rights.attribution.url'),
            Read::strings($data['where'] ?? null, 'rights.attribution.where'),
        );
    }

    /** @return array{required: bool, text: ?string, url: ?string, where: list<string>} */
    public function toArray(): array
    {
        return ['required' => $this->required, 'text' => $this->text, 'url' => $this->url, 'where' => $this->where];
    }
}
