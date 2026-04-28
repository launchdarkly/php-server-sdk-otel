# Changelog

All notable changes to the project will be documented in this file. This project adheres to [Semantic Versioning](http://semver.org).

## 0.1.0 (2026-04-28)


### Features

* add experimental addSpans option for per-evaluation spans ([#8](https://github.com/launchdarkly/php-server-sdk-otel/issues/8)) ([36b04e4](https://github.com/launchdarkly/php-server-sdk-otel/commit/36b04e4fe389b89e5bc0f6b95cbc55b77a824cd9))
* add optional feature_flag attributes (value, variationIndex, inExperiment) ([#6](https://github.com/launchdarkly/php-server-sdk-otel/issues/6)) ([5f43c4f](https://github.com/launchdarkly/php-server-sdk-otel/commit/5f43c4f3c8ff21930e4119e6ce287a0cc13d5513))
* add TracingHook with required feature_flag span event ([#5](https://github.com/launchdarkly/php-server-sdk-otel/issues/5)) ([1a3c016](https://github.com/launchdarkly/php-server-sdk-otel/commit/1a3c0164fb1983d12fe4211e2f4f9e0a330d9f0e))
* add TracingHookOptions and attribute constants ([#4](https://github.com/launchdarkly/php-server-sdk-otel/issues/4)) ([ae33939](https://github.com/launchdarkly/php-server-sdk-otel/commit/ae33939bfb5f53a46ef1b46f9843246a7483506c))
* emit feature_flag.set.id when environmentId is configured ([#7](https://github.com/launchdarkly/php-server-sdk-otel/issues/7)) ([26b6670](https://github.com/launchdarkly/php-server-sdk-otel/commit/26b6670befa6d84b421f15f4a3ca333d37d4cfd6))
* honor environmentId from EvaluationSeriesContext ([#13](https://github.com/launchdarkly/php-server-sdk-otel/issues/13)) ([d761dcb](https://github.com/launchdarkly/php-server-sdk-otel/commit/d761dcb01ac961bbd38a7cdb9b141e435bd005ad))
