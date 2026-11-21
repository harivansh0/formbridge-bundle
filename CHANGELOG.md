# Changelog

## 0.1.0 (2026-11-21)

First release.

- `#[AsFormTool]` exposes the form of a handler as an MCP tool, as the same form rendered inside
  the conversation (MCP Apps), and as a tool of Symfony AI agents.
- Agents can fill a form in and get its validation back. Saving takes the form shown to the
  person or the review page, once per draft.
- "Paste a message" page: a model reads free text into a form through structured output.
- Extension points: `FieldDescriberInterface`, `ConstraintDescriberInterface`, `FormSubmitEvent`,
  `FormDraftedEvent`, `FormSavedEvent`, overridable templates.
- Configuration: `agents`, `mcp_servers`, `review_page`, `drafts.lifetime`, `drafts.cache_pool`,
  `fill`.
- `debug:form-tools` console command.
- German and French translations of the texts shown to people.
