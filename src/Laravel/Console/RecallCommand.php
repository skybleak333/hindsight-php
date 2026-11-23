<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Console;

use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class RecallCommand extends Command
{
    protected $signature = 'hindsight:recall
        {bank : The memory bank to search}
        {query : The message to search for}
        {--tags=* : A tag to filter on (repeatable)}
        {--tags-match=any_strict : any, all, any_strict, all_strict or exact}
        {--type=* : world, experience or observation (repeatable)}
        {--budget=low : low, mid or high}
        {--max-tokens=1024 : The token limit for the returned memories}';

    protected $description = 'Show what Hindsight recalls for a message';

    public function handle(): int
    {
        /** @var string $bank */
        $bank = $this->argument('bank');
        /** @var string $query */
        $query = $this->argument('query');
        /** @var list<string> $tags */
        $tags = $this->option('tags');
        /** @var list<string> $types */
        $types = $this->option('type');

        if (trim($bank) === '' || trim($query) === '') {
            $this->error('The bank and query must both be non-empty.');

            return self::INVALID;
        }

        $budget = $this->option('budget');
        $budget = is_string($budget) ? Budget::tryFrom($budget) : null;
        if ($budget === null) {
            $this->error('The --budget must be low, mid or high.');

            return self::INVALID;
        }

        $tagsMatch = $this->option('tags-match');
        $tagsMatch = is_string($tagsMatch) ? TagsMatch::tryFrom($tagsMatch) : null;
        if ($tagsMatch === null) {
            $this->error('The --tags-match must be any, all, any_strict, all_strict or exact.');

            return self::INVALID;
        }

        if (array_diff($types, ['world', 'experience', 'observation']) !== []) {
            $this->error('The --type must be world, experience or observation.');

            return self::INVALID;
        }

        $maxTokens = filter_var($this->option('max-tokens'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($maxTokens === false) {
            $this->error('The --max-tokens must be a non-negative integer.');

            return self::INVALID;
        }

        try {
            $memories = $this->laravel->make(Hindsight::class)->recall(
                $bank,
                $query,
                types: $types ?: null,
                budget: $budget,
                maxTokens: $maxTokens,
                tags: $tags === [] && $tagsMatch !== TagsMatch::Exact ? null : $tags,
                tagsMatch: $tagsMatch,
            );
        } catch (HindsightException $exception) {
            if ($exception instanceof NotFoundException && $exception->isMissingBank($bank)) {
                $this->info('No memories yet (0 results).');

                return self::SUCCESS;
            }

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($memories->isEmpty()) {
            $this->info('No matching memories (0 results).');

            return self::SUCCESS;
        }

        // Preserve the memory text and line breaks without interpreting console markup or terminal controls.
        $prompt = preg_replace('/[^\P{Cc}\n\t]/u', '', $memories->toPrompt()) ?? '';
        $this->output->writeln($prompt, OutputInterface::OUTPUT_RAW);
        $count = count($memories);
        $this->info($count.' '.($count === 1 ? 'result.' : 'results.'));

        return self::SUCCESS;
    }
}
