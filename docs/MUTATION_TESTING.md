# Mutation-testing policy

Mutation testing is a targeted evidence lane, not a second copy of the ordinary
pull-request suite.

The complete matrix runs on its weekly schedule and through manual workflow
dispatch. A pull request runs it only when a maintainer applies the
`mutation-required` label and the change touches one of the workflow's selected
high-risk paths. Adding the label retriggers the workflow. Ordinary changes use
the control-plane tests, coverage, PHPStan, Pint and security gates instead.

Current blocking floors are evidence ratchets, not uniform quality scores:

| Scope | Minimum MSI |
| --- | ---: |
| JWT authorization | 85 |
| Signed events | 80 |
| Credits | 40 |
| Discovery scoring | 30 |
| Extracted capability, availability, readiness, pricing, stability, eligibility, ranking, endpoint and registration policies | 60 |

Raise a floor only after a fresh scheduled or manual run passes the proposed
value twice without increasing the allowed timeout count. Record the measured
result and surviving-mutant classes in the related issue or pull request. Do
not lower a floor or increase timeouts merely to make a regression pass.

The differing floors reflect the cost and current characterization depth of
each area. They do not mean that a 30 or 40 percent scope is complete.
