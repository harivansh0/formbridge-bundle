# Changelog

## 0.1.1 (2026-11-21)

- Option labels are quoted when they are described to a model, in tool descriptions and in a
  form's answer, so that a label cannot pass for a second option.
- A name clash with a `#[McpTool]` service is only an error when both are on the same MCP server.
- Building the container fails when a server gets the form tools but its registry leaves out
  the form's screen (`FormBridge\Mcp\FormBridgeApp`).
- A chained cache pool is also refused when the profiler wraps it.
- An agent cannot run a form tool that is not exposed.

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
