# anylint

*[Українською](README.uk.md)*

A cross-platform static code analyzer with a language-plugin architecture — not a separate linter per language, but one core (file discovery, reporting, CLI) plus a `LanguageProvider` that converts a language's native AST into one shared canonical form. Rules are written once and work for any language whose provider correctly emits that form.

## Why this isn't just another PHP linter

This is proven, not just claimed: `NyxilumProvider` plugs in [NyxilumLang](https://github.com/Faneraiy14/NyxilumLang) — a completely different language with its own lexer/parser/VM, `JavaScriptProvider`/`TypeScriptProvider` route the file through the TypeScript compiler API, and the remaining fourteen languages (C, C++, C#, Java, Python, Rust, Swift, Go, Kotlin, Ruby, Dart, Zig, Objective-C, Solidity) go through tree-sitter. Structural rules catch the same classes of bugs in all of them as in `.php` — **without changing a single line of rule code**.

Rules come in three kinds — all behind ONE shared `Rule` interface:

- **Structural** (`DeadCodeAfterReturnRule`, `EmptyCatchRule`) — look only at the canonical tree (`Block`/`Return`/`TryCatch`/`CatchClause`). Their code has no idea PHP or NyxilumLang even exist.
- **Textual** (`TodoTrackerRule`, `HardcodedSecretRule`) — scan the raw file text, ignoring the AST entirely. They work on literally any file, even one with no registered provider for its extension.
- **Language-specific** (`UnusedVariableRule`) — the semantics of "unused variable" differ too much between languages to generalize without losing meaning, so this rule reads the native `PhpParser\Node` directly (the provider deliberately keeps it around on every `FunctionDecl` node). Same `Rule` interface — just a narrower scope of applicability.

### How `NyxilumProvider` works

`nx ast file.nx` ([AstJsonDumper.cs](https://github.com/Faneraiy14/NyxilumLang/blob/main/src/NyxilumLang/Tools/AstJsonDumper.cs) in NyxilumLang itself) outputs the AST directly in the canonical JSON schema `{"type","line","attributes","children"}` — the same one `PhpProvider` builds from the `nikic/php-parser` tree. `NyxilumProvider` here just runs that process and does `json_decode` — there's no node-type mapping at all, because the shared vocabulary ("what counts as `Block`/`Return`/`CatchClause`") lives on NyxilumLang's side. Requires `nx` in `PATH` (or `NX_EXE=/path/to/nx`) — if it's missing, the file being analyzed gets a `parse-error` Finding instead of derailing the whole run.

### How `JavaScriptProvider`/`TypeScriptProvider` work

Same trick as `nx ast`, just with a different executable: the project's own `tools/js-ast-dump/dump.js`. The TypeScript compiler API (the `typescript` package, which parses both `.js` and `.ts` — `allowJs` there is only about type checking, the parser itself is shared) walks the `ts.Node` tree and emits that same canonical JSON schema itself. `.js`/`.jsx`/`.mjs`/`.cjs` and `.ts`/`.tsx` are two separate provider classes (one plugin = one language), but both share the process-launching logic via `AbstractJsFamilyProvider`. Requires `node` in `PATH` (or `NODE_EXE=...`) and installed dependencies in `tools/js-ast-dump` (`npm install` there once) — without that, `.js`/`.ts` files likewise get a `parse-error` Finding rather than a silent failure of the whole run.

### How the tree-sitter providers work (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Ruby/Dart/Zig/Objective-C/Solidity)

There's no single shared official compiler API for all these languages (unlike TypeScript for JS/TS), so this uses [tree-sitter](https://tree-sitter.github.io/) via `web-tree-sitter` (a WASM runtime, NO native compilation) with precompiled `.wasm` grammars from the `tree-sitter-wasms` package. `tools/treesitter-ast-dump/dump.js` takes the language as its FIRST argument and picks the right grammar itself — the same process as for JS/TS, just one engine serving fourteen languages at once.

Each tree-sitter grammar names its own "block of code" and "return" differently — that mapping is pinned down in `LANG_CONFIG` inside dump.js, not guessed on the fly:

- **"Block of code"**: `compound_statement` in C/C++/Objective-C, `block` in C#/Java/Python/Rust/Go, `statements` in Swift/Kotlin (there's no separate "block" node type there — a function body and an `if`/`do`/`for`/`while` body are the same node type). Ruby has no single such node at all — a method body is `body_statement`, an `if`/`rescue` body is `then`, so `cfg.block` is an array there, not a string. Solidity's `function_body` isn't wrapped in its own `block_statement` — its direct children are already the function's statements, so `cfg.block` is an array there too (`['block_statement', 'function_body']`), otherwise dead code right at a function's top level would go undetected.
- **`return`**: in Rust and Zig it's `return_expression`, wrapped in `expression_statement` (return is an expression there, not a statement) — without an explicit unwrap (`unwrapExpressionStatement()` in dump.js) it would be a grandchild of the block rather than a direct child, and `dead-code-after-return` (which only looks at `Block`'s direct children) would never see it. In Swift, `return`/`break`/`continue`/`throw`, and in Kotlin `return`/`break`/`continue`, are all ONE node type (`control_transfer_statement`/`jump_expression`); they're distinguished only by the text of the first token, so `isReturn()` in dump.js checks exactly that, not the node type itself.
- **`catch`/`except`/`rescue`**: Python calls it `except`, Ruby calls it `rescue`, same semantics. Rust, Go, and Zig don't have a traditional exception mechanism (Result/panic, defer/recover, and `catch`-as-a-builtin respectively) — there `tryStmt`/`catchClause` are simply `null`, and no TryCatch/CatchClause nodes ever appear. Dart and Solidity have an interesting quirk: in Dart, a `catch` body is NOT a child of `catch_clause` — it's the next SIBLING under the same `try_statement`; in Ruby, a `begin` body (the try-equivalent) isn't wrapped in a separate block at all — the try-part's statements are DIRECT children of `begin`, interleaved with `rescue`/`ensure` as siblings. Both cases are handled by a dedicated `mapTryCatchChildren()` in dump.js, not generic logic.

Requires `node` in `PATH` and `npm install` in `tools/treesitter-ast-dump` — same as for the JS/TS dumper.

Not every language in `tree-sitter-wasms` made the cut: Lua (in the bundled `.wasm` build, the grammar breaks on ANY multi-line function body — an obvious bug in that specific build) and Scala (parses `return <value>` as a syntax error, though a bare `return` works fine) produced false `parse-error` results on perfectly ordinary working code — better to honestly not support a language than ship a provider that gets in the way more than it helps. Bash is also on hold for now: `return` isn't its own node type there (it's just a command invocation named "return", requiring a text check), and `if`/`while` bodies aren't wrapped in a dedicated block node at all — support would end up noticeably weaker than for the other languages.

## Installation and usage

```bash
composer install
php bin/anylint path/to/code
```

For analyzing `.js`/`.ts` and all the tree-sitter languages (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Ruby/Dart/Zig/Objective-C/Solidity), you also need, once:

```bash
cd tools/js-ast-dump && npm install && cd -
cd tools/treesitter-ast-dump && npm install && cd -
```

```bash
php bin/anylint src --json      # machine-readable format, exit 1 if there are findings
php bin/anylint src --no-todo   # skip the todo-tracker
```

## What it catches today

| Rule | Type | What it finds |
|---|---|---|
| `dead-code-after-return` | structural | code right after a `return` that will never execute |
| `deep-nesting` | structural | `if`/`for`/`while`/`try` nested more than 4 levels deep — hard to read and test |
| `empty-block` | structural | an empty `if`/`for`/`foreach`/`while`/`do` body — forgotten logic or a stub |
| `empty-catch` | structural | a `catch` with no action inside — an error silently swallowed |
| `empty-function` | structural (FunctionDecl: every language except Objective-C) | an empty function body — a forgotten implementation or a stub |
| `long-function` | structural (same coverage boundary as `empty-function`) | over 30 top-level statements in a function — worth splitting up |
| `unused-variable` | PHP-specific | a variable is assigned and never referenced again |
| `hardcoded-secret` | textual | GitHub/AWS tokens, private keys, `password = "..."` right in the code |
| `todo-tracker` | textual | `// TODO` / `# FIXME` in comments (not in strings or identifiers) |

## Architecture

```
src/
  Ast/Node.php          — the canonical node (type, line, attributes, children, native)
  LanguageProvider.php  — the language-plugin interface: supports($file), parse($file): Node
  Rule.php               — the rule interface: check($root, $source, $file): Finding[]
  Providers/PhpProvider.php     — nikic/php-parser -> canonical tree
  Providers/NyxilumProvider.php — `nx ast` (JSON) -> canonical tree
  Providers/AbstractJsFamilyProvider.php — shared launch of tools/js-ast-dump/dump.js -> canonical tree
  Providers/JavaScriptProvider.php, TypeScriptProvider.php — file extensions for AbstractJsFamilyProvider
  Providers/AbstractTreeSitterProvider.php — shared launch of tools/treesitter-ast-dump/dump.js -> canonical tree
  Providers/CProvider.php, CppProvider.php, CSharpProvider.php, JavaProvider.php,
    PythonProvider.php, RustProvider.php, SwiftProvider.php, GoProvider.php,
    KotlinProvider.php, RubyProvider.php, DartProvider.php, ZigProvider.php,
    ObjectiveCProvider.php, SolidityProvider.php — file extensions + tree-sitter language for AbstractTreeSitterProvider
tools/js-ast-dump/dump.js          — TypeScript compiler API -> the same canonical JSON schema as "nx ast"
tools/treesitter-ast-dump/dump.js  — tree-sitter (14 languages, LANG_CONFIG) -> the same canonical JSON schema
  Rules/*                — the rules themselves
  Analyzer.php            — file traversal, provider selection, running the rules
bin/anylint                — the CLI
```

Adding a new language = write one `LanguageProvider` class and register it with `->withProvider(new ...)` in `bin/anylint` — no changes to the core or to any existing structural/textual rule.

## Resilience against new PHP releases

CI runs the test suite across a matrix of PHP versions (8.1–8.4) plus a separate nightly build — so an incompatibility with a new PHP release shows up in a few minutes in Actions, not painfully a year later by hand. The only real dependency is `nikic/php-parser`, an actively maintained package that keeps itself updated for new PHP versions; `composer.lock` is committed for reproducible builds.

## License

MIT, see [LICENSE](LICENSE).
