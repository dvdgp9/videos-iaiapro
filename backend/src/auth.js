// Bearer-token middleware. Defence in depth: the worker listens only on
// 127.0.0.1, but we still require a shared token between PHP and Node so
// that any local process on the box cannot trigger renders at will.

import { config } from './config.js';

export function requireToken(req, res, next) {
    const header = req.headers.authorization || '';
    const match  = header.match(/^Bearer\s+(.+)$/i);
    if (!match || match[1] !== config.token) {
        return res.status(401).json({ error: 'unauthorized' });
    }
    return next();
}
