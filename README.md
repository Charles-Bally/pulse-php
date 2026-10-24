# pulse-php

Le cœur commun de [`pulse-laravel`](https://packagist.org/packages/pulse-si/laravel) et [`pulse-symfony`](https://packagist.org/packages/pulse-si/symfony). Tu n'as normalement pas à l'installer toi-même : c'est lui qui parle à l'API [Pulse](https://pulse-app.si), et cette page sert surtout à qui veut savoir exactement ce qui sort de son app.

## Ce qu'il fait

```
                 ┌──────────────────────────── pulse-php ─────────────────────────────┐
 ton framework ▶ │ Probe       SELECT 1 / PING / taille de file, 5 s au plus           │
 (détection)     │ Selection   PULSE_SEND, PULSE_INCLUDE, PULSE_EXCLUDE                │
                 │ CronStore   passages des tâches, partagés entre processus          │
 Monolog ──────▶ │ SpoolHandler  journaux nettoyés vers un tampon local (1000 lignes)  │
                 │ Sealer      HPKE (RFC 9180) si PULSE_PUBLIC_KEY                     │──▶ api.pulse-app.si
                 │ Worker      rythme dicté par l'API, accéléré en direct              │    (HTTPS seulement)
                 └─────────────────────────────────────────────────────────────────────┘
```

- **Extensions courantes seulement** : `json`, `curl` (repli sur les flux de PHP), `sodium` et `openssl` pour le mode sécurisé et l'épinglage d'un relais, `pcntl` pour l'arrêt propre du worker. PHP 8.1 et plus.
- **Ne lève jamais** : une panne de Pulse ne doit pas faire tomber l'app surveillée.
- **Rien de métier** : l'état des dépendances, les passages des tâches, les versions, et les journaux si tu les envoies.

## Ce qui part, mot pour mot

Un rapport, toutes les 30 secondes en Pro (le rythme vient de l'API) :

```json
{
  "packageName": "pulse-laravel",
  "packageVersion": "0.1.0",
  "framework": "laravel",
  "frameworkVersion": "13.38.0",
  "appVersion": "2.3.0",
  "deployedAt": "2026-10-22T08:00:00.000Z",
  "checks": [{ "name": "PostgreSQL", "kind": "database", "engine": "pgsql", "ok": true, "latencyMs": 4 }],
  "crons": [{ "name": "relance-abonnements", "schedule": "0 * * * *", "lastRunOk": false, "error": "Disque plein" }]
}
```

Un lot de journaux, toutes les 10 secondes (chaque seconde quand quelqu'un regarde) :

```json
{ "entries": [{ "at": "2026-10-22T12:12:56.000Z", "level": "ERROR", "context": "production", "message": "Paiement refusé token=***" }] }
```

En mode sécurisé, chacun devient `{ "sealed": { "v": 1, "kid", "enc", "ct" }, "clear": { … } }` où `clear` ne contient que ce que tu as choisi de laisser lisible. Le nettoyage des secrets, les limites de taille et le chiffrement sont ceux de [`@pulse-si/core`](https://www.npmjs.com/package/@pulse-si/core), à l'octet près.

## Développement

Les tests et l'analyse tournent dans Docker, sans PHP sur la machine :

```bash
docker build -t pulse-php-dev:8.4 packages/pulse-php/docker
docker run --rm -v "$PWD/packages":/packages -w /packages/pulse-php pulse-php-dev:8.4 \
  sh -c 'composer install && composer test && composer analyse'
```

Même commande dans `pulse-laravel` et `pulse-symfony`.

## Licence

MIT
