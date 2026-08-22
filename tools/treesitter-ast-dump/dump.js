#!/usr/bin/env node
'use strict';

// Виводить AST файлу C/C++/C#/Java/Python/Rust/Swift/Go у канонічній
// JSON-схемі AnyLint ({type, line, attributes, children}) - тій самій,
// яку PhpProvider будує з nikic/php-parser, "nx ast" видає для
// NyxilumLang, і tools/js-ast-dump/dump.js видає для JS/TS. Один движок
// (tree-sitter, через web-tree-sitter - без нативної компіляції, лише
// прекомпільовані .wasm-граматики з tree-sitter-wasms) обслуговує ВСЮ
// цю групу мов - у PHP-коді CProvider/CppProvider/.../GoProvider лише
// передають РІЗНІ argv[2] (мова) у ЦЕЙ ЖЕ dump.js, а самі назви вузлів
// для кожної мови (LANG_CONFIG нижче) - те, що НЕ узгоджене автоматично,
// бо кожна tree-sitter-граматика називає свій "блок коду" й "return"
// по-своєму (аж до того, що в Rust return - це ВИРАЗ, обгорнутий у
// expression_statement, а в Swift і return, і break, і continue - ОДИН
// тип вузла control_transfer_statement, що розрізняється лише за
// текстом першого токена).

const { Parser, Language } = require('web-tree-sitter');
const fs = require('fs');

const isReturnStatement = (n) => n.type === 'return_statement';

const LANG_CONFIG = {
  c: {
    wasm: 'tree-sitter-c.wasm', block: 'compound_statement',
    tryStmt: null, catchClause: null, foreach: null, isReturn: isReturnStatement,
  },
  cpp: {
    wasm: 'tree-sitter-cpp.wasm', block: 'compound_statement',
    tryStmt: 'try_statement', catchClause: 'catch_clause', foreach: 'for_range_loop', isReturn: isReturnStatement,
  },
  c_sharp: {
    wasm: 'tree-sitter-c_sharp.wasm', block: 'block',
    tryStmt: 'try_statement', catchClause: 'catch_clause', foreach: 'foreach_statement', isReturn: isReturnStatement,
  },
  java: {
    wasm: 'tree-sitter-java.wasm', block: 'block',
    tryStmt: 'try_statement', catchClause: 'catch_clause', foreach: 'enhanced_for_statement', isReturn: isReturnStatement,
  },
  python: {
    wasm: 'tree-sitter-python.wasm', block: 'block',
    // Python except (не catch!) - той самий структурний сенс (перехоплення винятку).
    tryStmt: 'try_statement', catchClause: 'except_clause',
    // Python "for" завжди foreach (немає C-стилю for), тож for_statement
    // тут - це Foreach, а не For.
    foreach: 'for_statement', isReturn: isReturnStatement,
  },
  rust: {
    wasm: 'tree-sitter-rust.wasm', block: 'block',
    // Rust не має try/catch (Result/panic замість винятків) - структурно
    // нема аналога, тож обидва null, так само як у C.
    tryStmt: null, catchClause: null, foreach: 'for_expression',
    // "return 1;" у Rust - це return_expression, обгорнутий у
    // expression_statement (return - вираз, не стейтмент), тож і сам тип
    // вузла інший, і unwrapExpressionStatement() нижче потрібен, щоб він
    // взагалі став ПРЯМОЮ дитиною Block, а не був похований на рівень
    // глибше.
    isReturn: (n) => n.type === 'return_expression',
  },
  swift: {
    // У Swift немає окремого вузла "блок коду" - і тіло функції, і тіло
    // if/do/for/while - це вузол "statements" (список стейтментів), тож
    // саме він тут відіграє роль cfg.block.
    wasm: 'tree-sitter-swift.wasm', block: 'statements',
    tryStmt: 'do_statement', catchClause: 'catch_block', foreach: null,
    // control_transfer_statement - ОДИН тип вузла для return/break/
    // continue/fallthrough/throw; розрізнити можна лише за текстом
    // першого (неіменованого) токена-ключового слова.
    isReturn: (n) => n.childCount > 0 && n.type === 'control_transfer_statement' && n.child(0).type === 'return',
  },
  go: {
    wasm: 'tree-sitter-go.wasm', block: 'block',
    // Go не має try/catch (defer/recover - інша модель, структурно не
    // мапиться на TryCatch/CatchClause) і не має окремого for_range -
    // "for" обслуговує і C-стиль, і foreach, тож лишаємо генеричний 'For'.
    tryStmt: null, catchClause: null, foreach: null, isReturn: isReturnStatement,
  },
};

const lang = process.argv[2];
const filePath = process.argv[3];
const cfg = LANG_CONFIG[lang];
if (!cfg || !filePath) {
  process.stderr.write(`Використання: node dump.js <${Object.keys(LANG_CONFIG).join('|')}> <файл>\n`);
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
  } else if (cfg.isReturn(node)) {
    canonicalType = 'Return';
  } else if (cfg.tryStmt !== null && type === cfg.tryStmt) {
    canonicalType = 'TryCatch';
  } else if (cfg.catchClause !== null && type === cfg.catchClause) {
    canonicalType = 'CatchClause';
  } else if (cfg.foreach !== null && type === cfg.foreach) {
    // Перевіряємо ПЕРЕД генеричним for_statement/for_expression - у
    // Python сам foreach ("for x in ...") - це той самий тип вузла
    // for_statement, що інші мови використовують для C-стилю for.
    canonicalType = 'Foreach';
  } else if (type === 'if_statement' || type === 'if_expression') {
    canonicalType = 'If';
  } else if (type === 'while_statement' || type === 'while_expression') {
    canonicalType = 'While';
  } else if (type === 'do_statement') {
    canonicalType = 'Do';
  } else if (type === 'for_statement' || type === 'for_expression') {
    canonicalType = 'For';
  } else {
    canonicalType = 'Other';
  }

  let children;
  if (canonicalType === 'CatchClause') {
    // Так само, як CatchClause у JS/TS-дампері: беремо ЛИШЕ дитину-блок
    // (тіло catch/except), ігноруючи параметр винятку - EmptyCatchRule
    // читає рівно catch.children[0] як тіло, а порядок дітей
    // (спершу параметр, потім блок) різний за назвою в кожній мові, тож
    // шукаємо блок за типом, а не за позицією. Якщо блоку-дитини взагалі
    // немає (Swift: "catch { }" без жодного стейтменту всередині
    // ВЗАГАЛІ не породжує вузол "statements" - на відміну від C-подібних
    // мов, де {} завжди лишається вузлом compound_statement/block навіть
    // порожнім) - синтезуємо порожній Block, інакше EmptyCatchRule
    // (яка читає catch.children[0]->children === []) просто не побачить
    // жодної дитини й мовчки пропустить цей найпоширеніший випадок.
    const body = findNamedChild(node, (c) => c.type === cfg.block);
    children = [body ? mapNode(body, false) : { type: 'Block', line, attributes: {}, children: [] }];
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
    result.push(unwrapExpressionStatement(node.namedChild(i)));
  }
  return result;
}

// Rust огортає КОЖЕН стейтмент-вираз (виклик функції, return-вираз
// тощо) у expression_statement - без розгортання return_expression був
// би не прямою дитиною Block, а онуком (Block -> expression_statement ->
// return_expression), і DeadCodeAfterReturnRule (яка сканує лише ПРЯМИХ
// дітей Block) ніколи не побачила б Return. Безпечно для решти мов -
// вони цей вузол не використовують узагалі.
function unwrapExpressionStatement(node) {
  while (node.type === 'expression_statement' && node.namedChildCount === 1) {
    node = node.namedChild(0);
  }
  return node;
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
