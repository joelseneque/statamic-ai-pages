@props(['suggested' => 'database'])

<x-ai-pages::notice type="warning">
    <strong>No queue worker — builds will time out.</strong>
    <p>
        A build makes a dozen or more API calls and takes minutes. With no queue it runs inside this request,
        and your web server will give up with a 504 long before it finishes — after the tokens have been spent.
    </p>
    <p>Set a connection in <code class="aip-inline">.env</code>:</p>
    <code class="aip-code">AI_PAGES_QUEUE={{ $suggested }}</code>
    <p>and keep a worker running:</p>
    <code class="aip-code">php artisan queue:work {{ $suggested }} --tries=1 --timeout=1800</code>
    <p class="aip-footnote" style="margin-top:.5rem">
        Or bypass the browser entirely: <code class="aip-inline">php please ai-pages:build</code> and
        <code class="aip-inline">php please ai-pages:sweep</code> have no time limit.
    </p>
</x-ai-pages::notice>
