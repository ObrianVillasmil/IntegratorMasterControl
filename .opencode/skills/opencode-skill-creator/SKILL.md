---
name: opencode-skill-creator
description: Guide to create, structure, and manage OpenCode agent skills with correct SKILL.md format, frontmatter, naming rules, and file placement.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  workflow: skill-creation
---

## What I do

I teach you how to create OpenCode agent skills with the correct structure, file placement, and naming conventions.

## Skill structure

Each skill lives in its own directory with a `SKILL.md` file:

```
.opencode/skills/<skill-name>/SKILL.md
```

### File placement (searched in order)

- Project config: `.opencode/skills/<name>/SKILL.md`
- Global config: `~/.config/opencode/skills/<name>/SKILL.md`
- Claude-compatible: `.claude/skills/<name>/SKILL.md`
- Agent-compatible: `.agents/skills/<name>/SKILL.md`

### YAML Frontmatter (required)

Every `SKILL.md` must start with:

```yaml
---
name: my-skill-name
description: Short description (1-1024 chars) that helps the agent decide when to load this skill
license: MIT
compatibility: opencode
metadata:
  audience: developers
  workflow: example
---
```

Only `name` and `description` are required. Unknown fields are ignored.

### Name validation rules

- 1-64 characters
- Lowercase alphanumeric with single hyphen separators
- Cannot start or end with `-`
- No consecutive `--`
- Must match the directory name containing `SKILL.md`
- Regex: `^[a-z0-9]+(-[a-z0-9]+)*$`

### Description rules

- 1-1024 characters
- Be specific enough for the agent to choose correctly
- Describe what the skill does and when to use it

## Skill body structure

After frontmatter, use markdown sections:

```markdown
## What I do
- Brief list of capabilities

## When to use me
- Conditions that trigger this skill

## Key concepts
- Domain-specific knowledge the agent needs

## File references
- Important files and their roles

## Patterns and conventions
- Code patterns, naming conventions, gotchas
```

## Discovery

OpenCode walks up from CWD to git worktree root, loading matching `skills/*/SKILL.md` files. Global definitions are also loaded from `~/.config/opencode/skills/*/SKILL.md`.

## Permissions (in opencode.json)

```json
{
  "permission": {
    "skill": {
      "*": "allow",
      "my-skill": "allow",
      "internal-*": "deny",
      "experimental-*": "ask"
    }
  }
}
```

## Troubleshooting

1. `SKILL.md` must be all caps
2. Frontmatter must include `name` and `description`
3. Skill names must be unique across all locations
4. Skills with `deny` permission are hidden from agents

## When to use me

Use this when creating a new skill, fixing a broken skill, or understanding the skill system structure.
