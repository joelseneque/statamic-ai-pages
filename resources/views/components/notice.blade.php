@props(['type' => 'info'])

@php
$classes = match($type) {
    'warning' => 'bg-amber-50 border-amber-300 text-amber-900 dark:bg-amber-900/20 dark:border-amber-700 dark:text-amber-100',
    'error'   => 'bg-red-50 border-red-300 text-red-900 dark:bg-red-900/20 dark:border-red-700 dark:text-red-100',
    'success' => 'bg-green-50 border-green-300 text-green-900 dark:bg-green-900/20 dark:border-green-700 dark:text-green-100',
    default   => 'bg-blue-50 border-blue-300 text-blue-900 dark:bg-blue-900/20 dark:border-blue-700 dark:text-blue-100',
};
@endphp

<div class="border rounded-lg p-4 mb-6 text-sm {{ $classes }}">
    {{ $slot }}
</div>
