<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Email Activity">
        <x-slot:icon>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
            </svg>
        </x-slot:icon>
    </x-pulse::card-header>

    <div class="grid grid-cols-2 gap-4 h-full content-center">
        <div class="flex flex-col gap-1">
            <span class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400">Sent (24h)</span>
            <span class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($sent24h) }}</span>
        </div>
        <div class="flex flex-col gap-1">
            <span class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400">Received (24h)</span>
            <span class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($received24h) }}</span>
        </div>
        <div class="flex flex-col gap-1">
            <span class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400">Scheduled</span>
            <span class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($scheduledPending) }}</span>
        </div>
        <div class="flex flex-col gap-1">
            <span class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400">Failed Accounts</span>
            <span class="text-2xl font-bold {{ $failedAccounts > 0 ? 'text-red-500' : 'text-gray-900 dark:text-gray-100' }}">
                {{ number_format($failedAccounts) }}
            </span>
        </div>
    </div>
</x-pulse::card>
