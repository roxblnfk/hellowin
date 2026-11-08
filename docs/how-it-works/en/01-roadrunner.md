[Contents](../../../README.md#how-it-works) · [Modules](02-modules.md) → | [Русский](../ru/01-roadrunner.md)

# RoadRunner: PHP that does not die

RoadRunner appeared at the end of 2017, and version 1.0 came out in June 2018. It was built to take PHP to the enterprise level and win some of that ground from Java. It all started with a question: what if a PHP application did not die after every request? That is how enterprise frameworks work in Java: the application boots once and serves requests until it is stopped. PHP in the classic model, by contrast, loads the configs, builds the container and opens the connections for every request — and throws it all away after the response. If the process lives on, that bootstrap happens once. That is the model RoadRunner keeps: a pool of long-lived PHP workers it hands the requests to.

The only sensible language for such a server back then was Go. But Go does not get along well with C, which PHP is written in, so a protocol of its own was needed between PHP and RoadRunner — [Goridge](https://github.com/roadrunner-server/goridge). It costs a tax on serialisation and deserialisation. In return, practice and time showed it to be the most stable and safe option, unlike embedding PHP into Go through CGO or talking to it over FastCGI ([a write-up](https://rapira.rs/blog/php2shell)).

The new model called for a new framework. The frameworks of the day could not stay alive: they leaked memory, and their habits counted on the process dying anyway. That is how [Spiral Framework](https://spiral.dev/) came about — this demo is written on it too. It needed an ORM that does not leak: Doctrine and Eloquent did not fit, and [Cycle ORM](https://cycle-orm.dev/) appeared.

The first versions proved the concept works, and new features started to hang on RoadRunner: the jobs queue, websockets, KV, locks, metrics, and later [Temporal](https://temporal.io/). RoadRunner gives PHP one API to different storages and message brokers and takes the IO-bound work on itself. This demo uses the `http`, `centrifuge`, `kv`, `lock`, `jobs` and `metrics` plugins — how exactly, the other tabs tell, starting with [“Modules”](02-modules.md).

In time Laravel and Symfony also learned to live in the long-running model, and RoadRunner integrations appeared for them. The official ones either work suboptimally or take little from RoadRunner beyond HTTP. We recommend the community bridges:

- [roadrunner-php/laravel-bridge](https://github.com/roadrunner-php/laravel-bridge) for Laravel — HTTP, gRPC, queues, KV and Temporal;
- [baldinof/roadrunner-bundle](https://github.com/Baldinof/roadrunner-bundle) for Symfony — HTTP, gRPC and KV as a cache.

## RoadRunner 3 and the honest downsides

RoadRunner 3 came out in September 2026. It extends flexibly with plugins and runs at many enterprises around the world. It has its problems, though:

- **It is hard to handle.** Not every application can run long-running, and an everyday PHP developer needs to learn a bit more to write for it.
- **Memory.** Every worker is a separate process with no shared opcache, so the memory use is high.
- **Marketing.** However technically great a thing we build, telling the whole world about it is a problem of its own for us.

And not everyone needs it: many applications on general-purpose frameworks never see a high load.

## What next: Rapira

PHP still needs a proper web server able to run any application in any mode: classic, worker or even asynchronous. So the idea of RoadRunner went further, and that is how [Rapira](https://rapira.rs/) came about, its continuation.

> RoadRunner is not abandoned: many companies run on it, and its support goes on. But in the long run, once Rapira covers everything RoadRunner does, the hope is that everyone moves over to it.

---

[Contents](../../../README.md#how-it-works) · [Modules](02-modules.md) → | [Русский](../ru/01-roadrunner.md)
