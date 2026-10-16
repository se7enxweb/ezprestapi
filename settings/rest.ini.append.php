<?php /* #?ini charset="utf-8"?

[ApiProvider]
# One provider serves both API versions: the reads answer at /api/ezp/v1/... and /api/ezp/v2/...,
# the writes (create, update, delete) at /api/ezp/v2/... only. ezpl is an alias prefix of the same routes.
# The deprecated ezprestapiprovider extension maps the same prefixes to this class, so the order in which
# the two are activated does not matter.
ProviderClass[ezp]=ezp7xRestApiProvider
ProviderClass[ezpl]=ezp7xRestApiProvider

# The template of the XHTML output of a node read (the OutputFormat=xhtml content variable), as the
# kernel names it for the controller of the old v1 provider ([ezpRestContentController_viewContent_OutputSettings]).
[ezp7xRestContentController_viewContent_OutputSettings]
Template=rest_pagelayout.tpl

*/ ?>
