# Security policy

This bundle stores translations that every page of an application renders, sends source strings
to third-party AI providers with the application's API keys, and imports files. A flaw here
reaches every visitor of the host application, so please report it privately.

## Reporting a vulnerability

**Do not open a public issue.** Use either of the two private channels:

- [GitHub security advisory](https://github.com/CylleneDigital/AiTranslationBundle/security/advisories/new)
  ("Report a vulnerability")
- email to sylius@groupe-cyllene.com

Please include the bundle version, the Symfony and PHP versions, the database, what an attacker
could do, and the smallest reproduction you have. **Redact every API key** from the logs and
configuration you attach.

## Response time

First response within **5 working days**. We keep you posted on the analysis, then on the fix and
its release date.

## Supported versions

| Version | Support |
|---|---|
| `1.x` | Bug and security fixes |

## Scope notes

Three behaviours are documented rather than defended, because they depend on how the host uses
the bundle (where it renders a value, what it sends to a provider, what it approves):

- **Translations are untrusted text.** A value can come from an AI provider or an imported file.
  `PlaceholderConsistencyChecker` compares placeholders; it does not sanitise markup and never
  rejects it. Escaping at render time stays the application's job: never emit a translated
  value through `|raw`, into an HTML attribute or a JavaScript context without the proper
  escaping.
- **Source strings reach a third party.** They are sent to the configured provider with the
  application's API key. Choosing which catalogues may be translated, and by whom, is a
  deployment decision.
- **A source string can steer the model.** It is part of the prompt: a crafted value (imported,
  or typed in a back office) can talk the model into altering the other strings of its batch
  and reporting a high confidence for them. The prompt tells the model to treat the strings as
  data, which lowers the odds, not the possibility. The bundle never applies a suggestion without
  a human decision; an integration must not approve on the self-reported confidence either.

Anything else (a way to read or write overrides without going through the host's own
authorisation, a way to make the bundle execute imported content, an injection through a
catalogue identifier or a scope code) is in scope, and we want to hear about it.

## Disclosure

Coordinated disclosure: the fix is released first, then the advisory. We are happy to credit the
reporter, unless they ask otherwise.
