<?php

namespace Omnisense\Contracts\Policy;

/**
 * Every declared policy in one JSON document, for a table of them (the
 * omnisense website's): a provider package names its policy file in its
 * composer.json,
 *
 *   "extra": {"omnisense": {"policy": "policy.json"}}
 *
 * and the export reads it from an application's vendor/composer/installed.json
 * or from package folders (a workspace's checkouts), each policy checked
 * by UsagePolicy::fromFile(). A package whose policy cannot be read is
 * listed with its error instead.
 *
 * A policy carries its plans in its "plans" member. During the transition, a
 * package whose plans are still in a file of their own names it too, and
 * the export puts them in the policy (UsagePolicy::withPlans()):
 *
 *   "extra": {"omnisense": {"policy": "policy.json", "plans": "plans.json"}}
 *
 * ("plans" a map of policy file to plans file for a package of several
 * policies.)
 */
final class PolicyExport
{
    /** @var list<array{package: string, version: ?string, file: string, policy?: array<string, mixed>, error?: string}> */
    private array $entries = [];

    /** The packages of an application, from its vendor/composer/installed.json. */
    public function installed(string $installedJson): self
    {
        $data = json_decode((string) @file_get_contents($installedJson), true);
        $packages = \is_array($data) ? ($data['packages'] ?? $data) : null;
        if (!\is_array($packages)) {
            throw new InvalidPolicyException(\sprintf('"%s" is not Composer\'s installed.json.', $installedJson));
        }
        $vendor = \dirname($installedJson);
        foreach ($packages as $package) {
            if (\is_array($package) && \is_string($package['name'] ?? null)) {
                $install = \is_string($package['install-path'] ?? null) ? $package['install-path'] : '../'.$package['name'];
                $path = str_starts_with($install, '/') ? $install : $vendor.'/'.$install;
                $this->add($package['name'], $package, $path, \is_string($package['version'] ?? null) ? $package['version'] : null);
            }
        }

        return $this;
    }

    /** Package folders, each with its composer.json. */
    public function folders(string ...$folders): self
    {
        foreach ($folders as $folder) {
            $data = is_file($folder.'/composer.json') ? json_decode((string) file_get_contents($folder.'/composer.json'), true) : null;
            if (\is_array($data) && \is_string($data['name'] ?? null)) {
                $this->add($data['name'], $data, $folder, null);
            }
        }

        return $this;
    }

    /** @return list<array{package: string, version: ?string, file: string, policy?: array<string, mixed>, error?: string}> by package name */
    public function entries(): array
    {
        $entries = $this->entries;
        usort($entries, static fn (array $a, array $b): int => [$a['package'], $a['file']] <=> [$b['package'], $b['file']]);

        return $entries;
    }

    public function toJson(): string
    {
        return json_encode(['schema' => 'https://github.com/glitchr-omnisense/contracts/blob/1.x/resources/usage-policy.schema.json', 'policies' => $this->entries()], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }

    /** @param array<mixed> $composer */
    private function add(string $name, array $composer, string $folder, ?string $version): void
    {
        $extra = \is_array($composer['extra'] ?? null) && \is_array($composer['extra']['omnisense'] ?? null) ? $composer['extra']['omnisense'] : [];
        $files = $extra['policy'] ?? $extra['policies'] ?? [];
        $plans = $extra['plans'] ?? null;
        foreach (\is_string($files) ? [$files] : (\is_array($files) ? $files : []) as $file) {
            if (!\is_string($file)) {
                continue;
            }
            $entry = ['package' => $name, 'version' => $version, 'file' => $file];
            try {
                $policy = UsagePolicy::fromFile(rtrim($folder, '/').'/'.$file);
                $separate = \is_string($plans) ? $plans : (\is_array($plans) && \is_string($plans[$file] ?? null) ? $plans[$file] : null);
                if (null !== $separate) {
                    $policy = null === $policy->plans ? $policy->withPlans(Plans::fromFile(rtrim($folder, '/').'/'.$separate)) : throw new InvalidPolicyException(\sprintf('%s declares its plans twice: in its policy and in "%s".', $policy->provider, $separate));
                }
                $entry['policy'] = $policy->toArray();
            } catch (InvalidPolicyException $e) {
                $entry['error'] = $e->getMessage();
            }
            $this->entries[] = $entry;
        }
    }
}
