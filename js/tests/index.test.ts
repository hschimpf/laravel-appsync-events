import { describe, expect, it } from 'vitest';

import * as connector from '../src/index.js';

describe('@hds-solutions/laravel-echo-appsync', () => {
    it('exports a module', () => {
        expect(connector).toBeTypeOf('object');
    });
});
