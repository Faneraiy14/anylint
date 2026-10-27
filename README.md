# anylint

*[Українською](README.uk.md)*

A cross-platform static code analyzer with a language-plugin architecture — not a separate linter per language, but one core (file discovery, reporting, CLI) plus a `LanguageProvider` that converts a language's native AST into one shared canonical form. Rules are written once and work for any language whose provider correctly emits that form.

## Why this isn't just another PHP linter

This is proven, not just claimed: `NyxilumProvider` plugs in [NyxilumLang](https://github.com/Faneraiy14/NyxilumLang) — a completely different language with its own lexer/parser/VM, `JavaScriptProvider`/`TypeScriptProvider` route the file through the TypeScript compiler API, and the remaining fifteen languages (C, C++, C#, Java, Python, Rust, Swift, Go, Kotlin, Lua, Ruby, Dart, Zig, Objective-C, Solidity) go through tree-sitter. Structural rules catch the same classes of bugs in all of them as in `.php` — **without changing a single line of rule code**.

Rules come in three kinds — all behind ONE shared `Rule` interface:

- **Structural** (`DeadCodeAfterReturnRule`, `EmptyCatchRule`) — look only at the canonical tree (`Block`/`Return`/`TryCatch`/`CatchClause`). Their code has no idea PHP or NyxilumLang even exist.
- **Textual** (`TodoTrackerRule`, `HardcodedSecretRule`) — scan the raw file text, ignoring the AST entirely. They work on literally any file, even one with no registered provider for its extension.
- **Language-specific** (`UnusedVariableRule`) — the semantics of "unused variable" differ too much between languages to generalize without losing meaning, so this rule reads the native `PhpParser\Node` directly (the provider deliberately keeps it around on every `FunctionDecl` node). Same `Rule` interface — just a narrower scope of applicability.

### How `NyxilumProvider` works

`nx ast file.nx` ([AstJsonDumper.cs](https://github.com/Faneraiy14/NyxilumLang/blob/main/src/NyxilumLang/Tools/AstJsonDumper.cs) in NyxilumLang itself) outputs the AST directly in the canonical JSON schema `{"type","line","attributes","children"}` — the same one `PhpProvider` builds from the `nikic/php-parser` tree. `NyxilumProvider` here just runs that process and does `json_decode` — there's no node-type mapping at all, because the shared vocabulary ("what counts as `Block`/`Return`/`CatchClause`") lives on NyxilumLang's side. Requires `nx` in `PATH` (or `NX_EXE=/path/to/nx`) — if it's missing, the file being analyzed gets a `parse-error` Finding instead of derailing the whole run.

### How `JavaScriptProvider`/`TypeScriptProvider` work

Same trick as `nx ast`, just with a different executable: the project's own `tools/js-ast-dump/dump.js`. The TypeScript compiler API (the `typescript` package, which parses both `.js` and `.ts` — `allowJs` there is only about type checking, the parser itself is shared) walks the `ts.Node` tree and emits that same canonical JSON schema itself. `.js`/`.jsx`/`.mjs`/`.cjs` and `.ts`/`.tsx` are two separate provider classes (one plugin = one language), but both share the process-launching logic via `AbstractJsFamilyProvider`. Requires `node` in `PATH` (or `NODE_EXE=...`) and installed dependencies in `tools/js-ast-dump` (`npm install` there once) — without that, `.js`/`.ts` files likewise get a `parse-error` Finding rather than a silent failure of the whole run.

### How the tree-sitter providers work (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Lua/Ruby/Dart/Zig/Objective-C/Solidity)

There's no single shared official compiler API for all these languages (unlike TypeScript for JS/TS), so this uses [tree-sitter](https://tree-sitter.github.io/) via `web-tree-sitter` (a WASM runtime, NO native compilation) with precompiled `.wasm` grammars from the `tree-sitter-wasms` package. `tools/treesitter-ast-dump/dump.js` takes the language as its FIRST argument and picks the right grammar itself — the same process as for JS/TS, just one engine serving fifteen languages at once.

Each tree-sitter grammar names its own "block of code" and "return" differently — that mapping is pinned down in `LANG_CONFIG` inside dump.js, not guessed on the fly:

- **"Block of code"**: `compound_statement` in C/C++/Objective-C, `block` in C#/Java/Python/Rust/Go/Lua, `statements` in Swift/Kotlin (there's no separate "block" node type there — a function body and an `if`/`do`/`for`/`while` body are the same node type). Ruby has no single such node at all — a method body is `body_statement`, an `if`/`rescue` body is `then`, so `cfg.block` is an array there, not a string. Solidity's `function_body` isn't wrapped in its own `block_statement` — its direct children are already the function's statements, so `cfg.block` is an array there too (`['block_statement', 'function_body']`), otherwise dead code right at a function's top level would go undetected.
- **`return`**: in Rust and Zig it's `return_expression`, wrapped in `expression_statement` (return is an expression there, not a statement) — without an explicit unwrap (`unwrapExpressionStatement()` in dump.js) it would be a grandchild of the block rather than a direct child, and `dead-code-after-return` (which only looks at `Block`'s direct children) would never see it. In Swift, `return`/`break`/`continue`/`throw`, and in Kotlin `return`/`break`/`continue`, are all ONE node type (`control_transfer_statement`/`jump_expression`); they're distinguished only by the text of the first token, so `isReturn()` in dump.js checks exactly that, not the node type itself. Lua goes further than any of them: `return` is required by the *grammar itself* to be the last statement of its block — writing anything after it in the same block is a genuine syntax error, not a style issue, so `dead-code-after-return` can only ever observe it there through the `do return end` early-exit idiom (a `return` inside a nested `do...end` scope, which is correctly not flagged for the outer block).
- **`catch`/`except`/`rescue`**: Python calls it `except`, Ruby calls it `rescue`, same semantics. An empty `except` is a Python syntax error, so `except: pass` *is* the Python form of `catch (e) {}`: dump.js drops `pass` from an `except` body only (`catchNoopStmt`), so `empty-catch` sees it as empty, while `def hook(self): pass` (an abstract-method/hook idiom) stays a normal statement. A comment next to that `pass` still marks the `except` as intentional, including one tree-sitter places above the body, outside the block. Rust, Go, Zig, and Lua don't have a traditional exception mechanism (Result/panic, defer/recover, `catch`-as-a-builtin, and `pcall`/`xpcall` — ordinary function calls, not syntax — respectively) — there `tryStmt`/`catchClause` are simply `null`, and no TryCatch/CatchClause nodes ever appear. Dart and Solidity have an interesting quirk: in Dart, a `catch` body is NOT a child of `catch_clause` — it's the next SIBLING under the same `try_statement`; in Ruby, a `begin` body (the try-equivalent) isn't wrapped in a separate block at all — the try-part's statements are DIRECT children of `begin`, interleaved with `rescue`/`ensure` as siblings. Both cases are handled by a dedicated `mapTryCatchChildren()` in dump.js, not generic logic.
- **Empty bodies with no block node at all**: braced languages always leave a `compound_statement`/`block` node even when `{}` is empty, so `empty-block`/`empty-function` just check that node's children. Lua has no braces anywhere (`if`/`while`/`for`/`repeat`/`function`...`end`) and its grammar simply omits the block node *entirely* when the body is empty — `if x then end` produces zero children, not a `block` with zero children. Verified directly against the parse tree before relying on it, not assumed. `funcBodyIsImplicit` (shared with Ruby, which has the same no-braces trait) and the newer `controlBodyIsImplicit` flag both synthesize an empty `Block` in exactly this situation, so the two emptiness rules see the same shape they'd see in any other language.
- **`for` isn't always one node type**: most languages reuse `for_statement`/`for_expression` for every kind of `for` loop (Python's C-style-less `for` included, hence `foreach: 'for_statement'` there). Lua's grammar instead splits numeric `for i = 1, 10 do` and generic `for k, v in pairs(t) do` into two distinct node types (`for_numeric_statement`/`for_generic_statement`), neither of which matches the default — handled via the `forStmt` config array rather than hardcoding a second special case into the shared matcher. Likewise `do_statement` means "do-while loop" in every other language here, but in Lua it's a plain lexical scope block (no loop semantics at all, commonly used for an early-exit `do return end`) — mapping it to `Do` would misclassify an ordinary scope as a loop, so `doStmt` lets Lua repoint the real loop-with-post-condition (`repeat...until`) at that canonical type instead and leaves the bare scope block as an untyped, non-control node.

Requires `node` in `PATH` and `npm install` in `tools/treesitter-ast-dump` — same as for the JS/TS dumper.

Not every language in `tree-sitter-wasms` made the cut: Scala (parses `return <value>` as a syntax error, though a bare `return` works fine — reproduced fresh against the currently pinned grammar version before writing this) produced false `parse-error` results on perfectly ordinary working code — better to honestly not support a language than ship a provider that gets in the way more than it helps. Bash is also on hold for now: `return` isn't its own node type there (it's just a command invocation named "return", requiring a text check), and `if`/`while` bodies aren't wrapped in a dedicated block node at all — support would end up noticeably weaker than for the other languages. (Lua used to be excluded here too, for a grammar bug that broke on any multi-line function body — re-tested against the currently pinned `tree-sitter-wasms` version while adding this section and the bug no longer reproduces, so Lua is supported now.)

## Installation and usage

```bash
composer install
php bin/anylint path/to/code
```

For analyzing `.js`/`.ts` and all the tree-sitter languages (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Lua/Ruby/Dart/Zig/Objective-C/Solidity), you also need, once:

```bash
cd tools/js-ast-dump && npm install && cd -
cd tools/treesitter-ast-dump && npm install && cd -
```

```bash
php bin/anylint src --json      # machine-readable format, exit 1 if there are findings
php bin/anylint src --checkstyle # Checkstyle XML — PhpStorm/other JetBrains IDEs import this
                                 # natively via a File Watcher, no custom plugin needed
php bin/anylint src --no-todo   # skip the todo-tracker
php bin/anylint src --sarif     # SARIF 2.1.0 — GitHub Code Scanning, GitLab, Azure DevOps
php bin/anylint src --fix       # apply auto-fixes, then report what is left
php bin/anylint src --no-cache  # ignore the result cache
php bin/anylint src --jobs auto # analyze in parallel (one process per CPU core)
```

Exit codes: `0` no findings, `1` findings, `2` usage/config error.

## Speed

- **One long-lived Node.js process per dumper**, not one per file. Starting `node` and loading the TypeScript compiler cost ~0.6 s per JS/TS file before; the dumpers now run with `--serve` (one JSON request per line on stdin, one JSON response per line on stdout) and `NodeWorker` keeps the process for the whole run. Measured on a real project (162 JS + 18 Java + 44 PHP files): **125 s → 7.2 s**, with byte-identical findings. Exception: Lua. Its tree-sitter grammar parses only the *first* file in a process correctly (every later parse reports a false syntax error), so Lua files still get a one-shot `node` each.
- **Result cache**: a file that hasn't changed isn't parsed again — the same project re-runs in **0.35 s**. The cache lives in the system temp directory (one file per analyzed root, like PHPStan), so nothing appears in your repo. It's tied to anylint's own code, the rule set, options and severities, and the external tool versions (`nx`); changing any of them drops it entirely. `parse-error` results are never cached (they may mean "node isn't installed" rather than "the file is broken"). `--no-cache` disables it.
- **`--jobs <N|auto>`** analyzes in N parallel processes (`pcntl_fork`, a dynamic work queue, findings in the same order as a sequential run). It's **off by default**: on the 2-core laptop it was developed on, every extra process pays the Node startup again, and the run got slower (7.7 s → 8.3 s with 4 jobs) — the CPU had no idle cores to give. Try it on a machine with free cores. Without the `pcntl` extension (e.g. Windows) it silently runs sequentially.
- Files are analyzed in sorted path order, so output is stable between runs.

## Configuration

anylint looks for `anylint.json` (or `.anylint.json`) starting from the analyzed path's directory and walking up to the filesystem root, so running it from any subdirectory picks up the project's config. `--config <file>` points at one explicitly, `--no-config` skips the lookup.

```json
{
  "rules": {
    "todo-tracker": "off",
    "empty-catch": "error",
    "deep-nesting": { "severity": "warning", "maxDepth": 3 },
    "long-function": { "maxStatements": 50 },
    "parse-error": "warning"
  },
  "exclude": ["generated", "src/**/*.gen.php", "legacy/old/"],
  "baseline": "anylint-baseline.json"
}
```

- A rule's value is a severity (`off`, `info`, `warning`, `error`) or an object with an optional `severity` plus that rule's options. Today `deep-nesting` (`maxDepth`, default 4) and `long-function` (`maxStatements`, default 30) take options.
- `parse-error` and `unreadable-file` (findings the analyzer emits itself) can be overridden too, e.g. when `nx`/`node` isn't installed on a machine.
- `exclude` uses `.gitignore`-like globs relative to the config file: a pattern without `/` matches a file or directory name at any depth; a pattern with `/` matches a path from the config's directory (and everything under it); `*`, `**`, `?` work as usual. The built-in skip list (`vendor`, `node_modules`, `dist`, ...) still applies.
- Unknown rule names, unknown options and bad values are an error (exit code 2), not silently ignored: a typo would otherwise look like a setting that took effect.

## Suppressing findings

The same comment directives work for every rule and every language, because they are read from the raw file text, not the AST:

```php
// anylint-disable-next-line empty-catch -- intentionally swallowed, see #42
try { flush(); } catch (\Throwable $e) {}

$legacy = deep_call(); // anylint-disable-line deep-nesting, long-function
```

```python
# anylint-disable-file todo-tracker
```

- `anylint-disable-next-line`, `anylint-disable-line`, `anylint-disable-file`, followed by rule names (comma or space separated). No rule names = all rules. Text after ` -- ` is a reason for humans.
- The directive must come right after a comment marker (`//`, `#`, `/*`, `*`, `--`, `;`, `<!--`). A string literal or a sentence that merely mentions the directive's name does nothing.
- The older `anylint:ignore` still works and silences every rule on its line.

## Baseline: adopting anylint in an existing project

```bash
php bin/anylint src --generate-baseline   # accept every current finding, exit 0
php bin/anylint src                       # from now on, only NEW findings are reported
```

The baseline file is `anylint-baseline.json` next to `anylint.json` (or in the current directory without a config); set `"baseline": "path.json"` in the config or pass `--baseline <file>` to choose another. Commit it: paths inside are relative to the baseline file.

A finding is matched by file, rule and message, with a count — not by line number (the same approach as PHPStan). Adding lines above an old finding doesn't make it "new" again, but a second identical finding in the same file is still reported. A `baseline` path in the config that doesn't exist yet is fine; `--baseline <file>` that doesn't exist is an error.

## Auto-fix

`--fix` applies every fix that rules attach to findings (today: `promotable-return-type`, which turns a `@return` docblock into a native return type), then re-analyzes and reports what is left. The number of applied fixes goes to stderr, so `--fix --json`/`--fix --sarif` stay machine-readable. Fixes are applied from the end of the file backwards; if two fixes overlap, only one is applied, and the next `--fix` run picks up the other.

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
| `promotable-return-type` | PHP-specific, with auto-fix | a method's `@return TYPE` docblock could be a native return type instead |
| `hardcoded-secret` | textual | GitHub/AWS tokens, private keys, `password = "..."` right in the code |
| `todo-tracker` | textual | `// TODO` / `# FIXME` / `-- TODO` in comments (not in strings or identifiers) |
| `windows-script-encoding` | textual, `.ps1`/`.bat`/`.cmd` only | Cyrillic (or other non-ASCII) without a UTF-8 BOM in `.ps1` — legacy Windows PowerShell 5.1 misreads it; any non-ASCII at all in `.bat`/`.cmd` — `cmd.exe`'s encoding handling is unreliable regardless of BOM |

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
    KotlinProvider.php, LuaProvider.php, RubyProvider.php, DartProvider.php, ZigProvider.php,
    ObjectiveCProvider.php, SolidityProvider.php — file extensions + tree-sitter language for AbstractTreeSitterProvider
tools/js-ast-dump/dump.js          — TypeScript compiler API -> the same canonical JSON schema as "nx ast"
tools/treesitter-ast-dump/dump.js  — tree-sitter (15 languages, LANG_CONFIG) -> the same canonical JSON schema
  Rules/*                — the rules themselves
  Formatters/CheckstyleFormatter.php — Finding[] -> Checkstyle XML (for --checkstyle)
  Formatters/SarifFormatter.php — Finding[] -> SARIF 2.1.0 (for --sarif)
  Baseline.php, Fixer.php — --generate-baseline/--baseline, --fix
  ResultCache.php, ParallelRunner.php — result cache, --jobs
  Providers/NodeWorker.php — one long-lived `node dump.js --serve` per dumper
  Config.php, PathMatcher.php — anylint.json loading/validation, exclude globs
  Suppressions.php        — anylint-disable-* comment directives, applied centrally for every rule
  Analyzer.php            — file traversal, provider selection, running the rules
bin/anylint                — the CLI
```

Adding a new language = write one `LanguageProvider` class and register it with `->withProvider(new ...)` in `bin/anylint` — no changes to the core or to any existing structural/textual rule.

## Resilience against new PHP releases

CI runs the test suite across a matrix of PHP versions (8.1–8.4) plus a separate nightly build — so an incompatibility with a new PHP release shows up in a few minutes in Actions, not painfully a year later by hand. The only real dependency is `nikic/php-parser`, an actively maintained package that keeps itself updated for new PHP versions; `composer.lock` is committed for reproducible builds.

## License

MIT, see [LICENSE](LICENSE).
