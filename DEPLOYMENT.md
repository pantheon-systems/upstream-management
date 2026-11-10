---
contract_version: 2
kind: library                       # a Composer plugin custom-upstream maintainers install; nothing runs from this repo
service: upstream-management

trigger: tag                        # a GitHub release (semver tag) is picked up by Packagist; consumers get it on their next `composer update`
merged_implies_deployed: false      # main is ahead of the latest release (1.0.1) by a return-type change and dev-dependency bumps
other_paths_to_prod:
  - "anyone requiring `dev-main` gets main directly (Packagist lists dev-main and every branch as dev-*) [inferred: no evidence anyone does]"
  - "re-pointing or deleting a tag changes what new installs resolve to (tags are unprotected)"

pipeline:
  system: github-actions
  workflow: ".github/workflows/ci.yml"   # 'CI Tests': on push (any ref), daily schedule 06:00 UTC, workflow_dispatch; tests only, publishes nothing
  jobs_in_order: [phpcompatibility, phpcs, functional]   # parallel; functional is a PHP 7.4 to 8.4 matrix
  typical_minutes: null             # TODO(verify-on-deploy): time a tag run and the Packagist pickup
  minutes_by_change: {}
  watch: "https://packagist.org/packages/pantheon-systems/upstream-management.json lists the new version with source.reference = the tag's commit"
  concurrency_group: null
  cancel_in_progress: false
  blocked_by_waiting_run: null
  approval_gate: null

repo_properties:
  observed_at: 2026-11-10
  trust_level: TL3
  ccb_status: in-ccb
  owner: developer-experience
  repo_type: tool                   # really a library (composer-plugin)

gating_checks:
  required: ["Wiz IaC Scanner", "Wiz Vulnerability Scanner", "Wiz Data Scanner"]   # org TL3; the repo ruleset adds the merge queue and no checks
  approvals: {required: 1, code_owner: true, last_push_approval: false, strict_up_to_date: true, merge_queue: true}
  run_but_not_required: ["PHP Compatibility", "PHP Coding Standards", "Functional testing matrix - PHP <v>"]
  conditional: []
  tags_protected: false
  code_owners: "@pantheon-systems/developer-experience (root CODEOWNERS)"

environments: []

release:
  mechanism: "create a GitHub release with a semver tag (no `v` prefix: 1.0.0, 1.0.1); Packagist updates through its webhook"
  latest: "1.0.1 (581530f, 2024-02-27); Packagist 1.0.1 source.reference = 581530f"   # verified 2026-11-10
  unreleased: "src/: `configure(): void` return types on both commands; plus dev-dependency and CI changes (#29, #43)"   # verified with git diff 1.0.1 origin/main
  never: "move or delete a published tag"

consumers:
  - repo: "custom upstream maintainers and their sites (public Packagist package, ~46k installs a month, 730k total)"   # verified from packagist.org stats 2026-11-10
    pin: "the upstream's composer.json / composer.lock"
    bumped_by: "the upstream maintainer's `composer update` [inferred]"
  - repo: "Pantheon docs: documentation src/source/content/partials/upstream-management-dependencies.md (tells custom upstream maintainers to require it)"
  - repo: "pantheon-systems/wordpress-composer-managed, pantheon-upstreams/wordpress-composer-managed, decoupled-wordpress-composer-managed (composer.json `suggest`, not `require`)"
  - repo: "pantheon-upstreams/uva-d10-upstream (requires it)"

deployed_when:
  - "packagist.org lists the new tag with source.reference equal to the tag's commit"

health:
  soak_minutes: null                # delegated: runs on maintainers' machines and CI
  baseline: null
  checks:
    - name: Packagist has the release
      kind: command
      run: "curl -s https://packagist.org/packages/pantheon-systems/upstream-management.json | jq -r '.package.versions[\"<tag>\"].source.reference'"
      healthy: "equals the tag's commit (1.0.1: 581530f69775cfd32acf944506de81510a28deca, verified)"
    - name: CI on the tag
      kind: command
      run: "gh run list -R pantheon-systems/upstream-management --workflow ci.yml --branch <tag> --limit 1 --json conclusion"
      healthy: "success; but see known_baseline_errors"
    - name: user reports
      kind: delegate
      run: "issues on this repo and #ask-hosting"
      healthy: "no new reports after a release"
  known_baseline_errors:
    - "'PHP Compatibility' fails on every run since 2026-11-07: pantheon-systems/phpcompatibility-action uses `shivammathur/setup-php@v2` unpinned, and the org now requires SHA-pinned actions. The other jobs pass"
  dashboards: []
  alerts: []

rollback:
  options:
    - when: "a new release is bad"
      kind: retract-and-repin
      steps: "revert on main (PR, 1 code-owner approval, merge queue) and publish a new patch release; consumers on a caret constraint move to it on their next update. Don't delete the bad tag"
      time_to_rollback_minutes: 30  # inferred
  not_restored: ["upstreams that already updated keep the bad version in composer.lock until they update again"]
  one_way_changes: []               # the plugin only edits composer files in the upstream that runs it
  currently_blocked: null
  last_exercised: null
  capture_before_merge: "git ls-remote --tags https://github.com/pantheon-systems/upstream-management | sort -V -k2 | tail -1"
  who_can: ["@pantheon-systems/developer-experience (revert PR); anyone with write can publish the release"]
  verify: "packagist.org lists the new tag with the expected source.reference"

escalation:
  slack: "#ask-hosting"
  owner_team: developer-experience
  paged: false

preflight:
  - "gh auth status"
  - "outbound HTTPS to packagist.org (public, no auth)"

automation_blockers:
  - blocker: "CI is red on main ('PHP Compatibility', see known_baseline_errors), and no CI job is a required check"
    clears_when: "phpcompatibility-action (owner site-experience) pins setup-php by SHA, or this repo stops using it; then make the tests required"
  - blocker: "no signal for the plugin's health in consumers"
    clears_when: "TODO(owner): decide whether user reports are enough for a plugin released twice in three years"
  - blocker: "tags are unprotected"
    clears_when: "a tag ruleset blocks updating or deleting release tags"

verified:
  at: null
  by: null
  change: null
  running_digest: null
---

# Deployment

`upstream-management` is a public Composer plugin (`pantheon-systems/upstream-management`) that custom-upstream maintainers install for the `upstream:require` and `upstream:update-dependencies` commands. **Nothing here builds or deploys.** A release is a GitHub release with a semver tag; Packagist picks it up, and consumers get it on their next `composer update`.

## How a change reaches production

1. **Merge to `main`** (one code-owner approval, merge queue). CI (`CI Tests`) runs PHP compatibility, phpcs and functional tests on PHP 7.4 to 8.4. None of them are required checks.
2. **Release:** create a GitHub release with a new semver tag. Existing tags have no `v` prefix (`1.0.0`, `1.0.1`); keep it that way. Packagist updates through its webhook [inferred; Packagist lists every branch as `dev-*`, so it is syncing].
3. **Consumers update** on their own `composer update`. `main` currently has unreleased changes since `1.0.1`: `configure(): void` return types on both commands, plus dev-dependency and CI changes.

TODO(verify-on-deploy): on the next release, time tag push to Packagist listing.

## How to confirm it deployed

```bash
curl -s https://packagist.org/packages/pantheon-systems/upstream-management.json \
  | jq -r '.package.versions["<tag>"].source.reference'
git ls-remote https://github.com/pantheon-systems/upstream-management "refs/tags/<tag>*"   # use the ^{} line if the tag is annotated
```

The two must match. On 2026-11-10, `1.0.1` resolved to `581530f69775cfd32acf944506de81510a28deca` in both.

## How to confirm it's healthy

Delegated to consumers. Before releasing, CI on the tag should pass. Known baseline: `PHP Compatibility` has failed on every run since 2026-11-07 because `pantheon-systems/phpcompatibility-action` uses `shivammathur/setup-php@v2` unpinned and the org now requires SHA-pinned actions; the other jobs pass. After a release, watch this repo's issues and `#ask-hosting`. No dashboards or alerts exist.

## Rollback

Capture the current latest tag before releasing: `git ls-remote --tags https://github.com/pantheon-systems/upstream-management | sort -V -k2 | tail -1`.

Revert the change on `main` (PR, one code-owner approval) and publish a new patch release, so caret constraints resolve to good code. **Don't delete or move the bad tag.** Upstreams that already updated stay on the bad version until they update again. Rollback has never been exercised. Verify with the Packagist check above.

## Escalation

`#ask-hosting` (developer-experience). Nobody is paged.

## Suggested pipeline changes

- Get `PHP Compatibility` green: pin `shivammathur/setup-php` by SHA inside `pantheon-systems/phpcompatibility-action` (owner site-experience), or call setup-php and PHPCompatibility directly here.
- Make the functional tests a required check.
- Add a tag ruleset that blocks updating or deleting release tags.
