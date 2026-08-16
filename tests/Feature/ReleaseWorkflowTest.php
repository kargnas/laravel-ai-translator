<?php

it('defines automatic and manual releases after a ten minute quiet period', function () {
    $workflow_path = dirname(__DIR__, 2).'/.github/workflows/release.yml';

    expect(file_exists($workflow_path))->toBeTrue();

    $workflow = file_get_contents($workflow_path);

    expect($workflow)
        ->toContain('push:')
        ->toContain('branches: [master]')
        ->toContain('workflow_dispatch:')
        ->toContain('type: choice')
        ->toContain('- patch')
        ->toContain('- minor')
        ->toContain('- major')
        ->toContain('cancel-in-progress: true')
        ->toContain('sleep 600')
        ->toContain('composer config version "${NEXT_VERSION}"')
        ->toContain('git push --atomic origin HEAD:master "refs/tags/${NEXT_VERSION}"')
        ->toContain('gh release create "${NEXT_VERSION}"');
});
