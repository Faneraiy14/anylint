#!/usr/bin/env node
'use strict';

// Виводить AST файлу .c/.h/.cpp/.hpp/.cs/.java у канонічній JSON-схемі
// AnyLint ({type, line, attributes, children}) - тій самій, яку
// PhpProvider будує з nikic/php-parser, "nx ast" видає для NyxilumLang, і
// tools/js-ast-dump/dump.js видає для JS/TS. На відміну від тих двох (де
// один конкретний парсер = одна мова), тут ОДИН движок (tree-sitter,
// через web-tree-sitter - без нативної компіляції, лише прекомпільовані
// .wasm-граматики з tree-sitter-wasms) обслуговує ВСЮ родину C-подібних
// мов - і в PHP-коді CProvider/CppProvider/CSharpProvider/JavaProvider
// лише передають РІЗНІ argv[2] (мова) у ЦЕЙ ЖЕ dump.js, а самі назви
// вузлів для кожної мови (LANG_CONFIG нижче) - це те, що НЕ узгоджене
// автоматично, бо кожна tree-sitter-граматика називає свій "блок коду"
// по-своєму: compound_statement (C/C++) проти block (C#/Java).

const { Parser, Language } = require('web-tree-sitter');
const fs = require('fs');

const LANG_CONFIG = {
  c: { wasm: 'tree-sitter-c.wasm', block: 'compound_statement', tryStmt: null, catchClause: null, foreach: null },
  cpp: { wasm: 'tree-sitter-cpp.wasm', block: 'compound_statement', tryStmt: 'try_statement', catchClause: 'catch_clause', foreach: 'for_range_loop' },
  c_sharp: { wasm: 'tree-sitter-c_sharp.wasm', block: 'block', tryStmt: 'try_statement', catchClause: 'catch_clause', foreach: 'foreach_statement' },
  java: { wasm: 'tree-sitter-java.wasm', block: 'block', tryStmt: 'try_statement', catchClause: 'catch_clause', foreach: 'enhanced_for_statement' },
};

const lang = process.argv[2];
const filePath = process.argv[3];
const cfg = LANG_CONFIG[lang];
if (!cfg || !filePath) {
  process.stderr.write('Використання: node dump.js <c|cpp|c_sharp|java> <файл>\n');
  process.exit(2);
}

let source;
try {
  source = fs.readFileSync(filePath, 'utf8');
} catch (e) {
  process.stderr.write(`Не вдалось прочитати файл: ${e.message}\n`);
  process.exit(2);
}

function mapNode(node, isRoot) {
  const line = node.startPosition.row + 1;
  const type = node.type;

  let canonicalType;
  if (isRoot) {
    canonicalType = 'Root';
  } else if (type === cfg.block) {
    canonicalType = 'Block';
  } else if (type === 'return_statement') {
    canonicalType = 'Return';
  } else if (cfg.tryStmt !== null && type === cfg.tryStmt) {
    canonicalType = 'TryCatch';
  } else if (cfg.catchClause !== null && type === cfg.catchClause) {
    canonicalType = 'CatchClause';
  } else if (type === 'if_statement') {
    canonicalType = 'If';
  } else if (type === 'while_statement') {
    canonicalType = 'While';
  } else if (type === 'do_statement') {
    canonicalType = 'Do';
  } else if (type === 'for_statement') {
    canonicalType = 'For';
  } else if (cfg.foreach !== null && type === cfg.foreach) {
    canonicalType = 'Foreach';
  } else {
    canonicalType = 'Other';
  }

  let children;
  if (canonicalType === 'CatchClause') {
    // Так само, як CatchClause у JS/TS-дампері: беремо ЛИШЕ дитину-блок
    // (тіло catch), ігноруючи параметр винятку - EmptyCatchRule читає
    // рівно catch.children[0] як тіло, а порядок дітей catch_clause
    // (спершу параметр, потім блок) різний за назвою в кожній мові, тож
    // шукаємо блок за типом, а не за позицією.
    const body = findNamedChild(node, (c) => c.type === cfg.block);
    children = body ? [mapNode(body, false)] : [];
  } else {
    children = namedChildren(node).map((c) => mapNode(c, false));
  }

  return {
    type: canonicalType,
    line,
    attributes: canonicalType === 'Other' ? { kind: type } : {},
    children,
  };
}

function namedChildren(node) {
  const result = [];
  for (let i = 0; i < node.namedChildCount; i++) {
    result.push(node.namedChild(i));
  }
  return result;
}

function findNamedChild(node, predicate) {
  for (const child of namedChildren(node)) {
    if (predicate(child)) {
      return child;
    }
  }
  return null;
}

(async () => {
  await Parser.init();
  const wasmPath = require.resolve(`tree-sitter-wasms/out/${cfg.wasm}`);
  const Lang = await Language.load(wasmPath);
  const parser = new Parser();
  parser.setLanguage(Lang);
  const tree = parser.parse(source);

  if (tree.rootNode.hasError) {
    process.stderr.write(`Parse Error: у файлі ${filePath} є синтаксична помилка (tree-sitter не зміг розібрати цю ділянку коду).\n`);
    process.exit(3);
  }

  process.stdout.write(JSON.stringify(mapNode(tree.rootNode, true)));
})();
