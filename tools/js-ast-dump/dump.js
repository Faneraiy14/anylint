#!/usr/bin/env node
'use strict';

// Виводить AST файлу .js/.jsx/.mjs/.cjs/.ts/.tsx у канонічній JSON-схемі
// AnyLint ({type, line, attributes, children}) - тій самій, яку
// PhpProvider будує з nikic/php-parser і яку "nx ast" видає напряму для
// NyxilumLang. JavaScriptProvider/TypeScriptProvider (PHP) лише
// запускають цей процес і роблять json_decode - жодного мапування типів
// вузлів на боці PHP немає, той самий принцип, що й у NyxilumProvider.
//
// На відміну від PhpProvider (який має рекурсивно шукати замикання
// findNestedClosures(), бо PhpNode\Stmt-дерево розділяє стейтменти й
// вирази), тут дерево ts.Node однорідне: ts.forEachChild() природно
// заходить усередину виразів, тож стрілочні функції/замикання БУДЬ-ДЕ
// (аргумент виклику, елемент масиву тощо) потрапляють у канонічне
// дерево самі, без окремого проходу.

const ts = require('typescript');
const fs = require('fs');

// Поточний файл. Модульна змінна, а не параметр кожної функції нижче: у
// режимі --serve один процес по черзі розбирає багато файлів, запити
// обробляються строго послідовно, тож перепризначати її перед кожним
// файлом безпечно. Сам require('typescript') (~0.6 с - майже вся ціна
// одноразового запуску) у --serve відбувається лише раз.
let sourceFile = null;

// Ті самі коди, що й код виходу в одноразовому режимі: 0 - успіх,
// 2 - проблема запуску/читання, 3 - справжня синтаксична помилка у файлі.
function dumpFile(filePath) {
  if (!filePath) {
    return { code: 2, error: 'Використання: node dump.js <файл.js|.ts|...>' };
  }
  let source;
  try {
    source = fs.readFileSync(filePath, 'utf8');
  } catch (e) {
    return { code: 2, error: `Не вдалось прочитати файл: ${e.message}` };
  }

  const isTsx = filePath.endsWith('.tsx') || filePath.endsWith('.jsx');
  const scriptKind = isTsx ? ts.ScriptKind.TSX : ts.ScriptKind.TS;

  sourceFile = ts.createSourceFile(
    filePath,
    source,
    ts.ScriptTarget.Latest,
    /* setParentNodes */ true,
    scriptKind,
  );

  // Синтаксична помилка в межах TS-парсера не кидає виняток - вона осідає
  // в sourceFile.parseDiagnostics. PhpProvider для аналогічного випадку
  // кидає RuntimeException із текстом помилки (перетворюється на
  // parse-error Finding в Analyzer), тому тут - той самий контракт: код
  // 3 + повідомлення, а не мовчазне "порожнє" AST.
  const diagnostics = sourceFile.parseDiagnostics || [];
  if (diagnostics.length > 0) {
    const first = diagnostics[0];
    const { line } = sourceFile.getLineAndCharacterOfPosition(first.start || 0);
    const message = ts.flattenDiagnosticMessageText(first.messageText, '\n');
    return { code: 3, error: `Parse Error: рядок ${line + 1}: ${message}` };
  }

  return {
    code: 0,
    ast: {
      type: 'Root',
      line: 1,
      attributes: {},
      children: [mapStatementList(sourceFile.statements, 1)],
    },
  };
}

function lineOf(node) {
  return sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1;
}

function isFunctionLike(node) {
  return (
    ts.isFunctionDeclaration(node) ||
    ts.isFunctionExpression(node) ||
    ts.isArrowFunction(node) ||
    ts.isMethodDeclaration(node) ||
    ts.isConstructorDeclaration(node) ||
    ts.isGetAccessorDeclaration(node) ||
    ts.isSetAccessorDeclaration(node)
  );
}

function functionName(node) {
  if (node.name && ts.isIdentifier(node.name)) {
    return node.name.text;
  }
  if (ts.isConstructorDeclaration(node)) {
    return 'constructor';
  }
  return '{closure}';
}

// Синтетичний Block навколо стейтментів SourceFile - щоб dead-code-
// after-return ловив мертвий код і на верхньому рівні модуля, так само,
// як усередині function-тіл (той самий трюк, що й Root -> [Block] у
// PhpProvider::parse()).
function mapStatementList(statements, line) {
  return {
    type: 'Block',
    line,
    attributes: {},
    children: statements.map(mapNode),
  };
}

function mapNode(node) {
  const line = lineOf(node);

  if (ts.isBlock(node)) {
    return mapStatementList(node.statements, line);
  }

  if (isFunctionLike(node)) {
    return {
      type: 'FunctionDecl',
      line,
      attributes: { name: functionName(node) },
      // forEachChild нижче однаково зайде в параметри/тип/декоратори -
      // навмисно НЕ обмежуємо тут children лише тілом, бо тоді замикання
      // у типах-параметрах за замовчуванням (рідкість, але можливо)
      // випали б із дерева непомітно.
      children: collectChildren(node).map(mapNode),
    };
  }

  if (ts.isReturnStatement(node)) {
    return { type: 'Return', line, attributes: {}, children: collectChildren(node).map(mapNode) };
  }

  if (ts.isTryStatement(node)) {
    return { type: 'TryCatch', line, attributes: {}, children: collectChildren(node).map(mapNode) };
  }

  if (ts.isCatchClause(node)) {
    // Навмисно ІГНОРУЄМО node.variableDeclaration (біндинг "catch (e)")
    // і беремо лише node.block - EmptyCatchRule читає рівно
    // catch.children[0] як тіло catch-у; якби першою дитиною міг стати
    // біндинг параметра, перевірка "тіло порожнє" ламалась би для
    // найпоширенішого випадку catch (e) { }.
    return { type: 'CatchClause', line, attributes: {}, children: [mapNode(node.block)] };
  }

  if (ts.isIfStatement(node)) {
    return { type: 'If', line, attributes: {}, children: collectChildren(node).map(mapNode) };
  }
  if (ts.isWhileStatement(node)) {
    return { type: 'While', line, attributes: {}, children: collectChildren(node).map(mapNode) };
  }
  if (ts.isDoStatement(node)) {
    return { type: 'Do', line, attributes: {}, children: collectChildren(node).map(mapNode) };
  }
  if (ts.isForStatement(node)) {
    return { type: 'For', line, attributes: {}, children: collectChildren(node).map(mapNode) };
  }
  if (ts.isForInStatement(node) || ts.isForOfStatement(node)) {
    return { type: 'Foreach', line, attributes: {}, children: collectChildren(node).map(mapNode) };
  }

  return {
    type: 'Other',
    line,
    attributes: { kind: ts.SyntaxKind[node.kind] },
    children: collectChildren(node).map(mapNode),
  };
}

function collectChildren(node) {
  const children = [];
  ts.forEachChild(node, (child) => {
    children.push(child);
  });
  return children;
}

// --serve: один процес на весь прогін anylint. Запит - рядок JSON
// {"file": "..."}, відповідь - рядок JSON {"code", "ast"} або
// {"code", "error"}. Помилка в одному файлі не валить процес.
async function serve() {
  const rl = require('readline').createInterface({ input: process.stdin, crlfDelay: Infinity });
  for await (const line of rl) {
    if (line.trim() === '') {
      continue;
    }
    let response;
    try {
      response = dumpFile(JSON.parse(line).file);
    } catch (e) {
      response = { code: 2, error: `Внутрішня помилка dump.js: ${e && e.stack ? e.stack : e}` };
    }
    process.stdout.write(JSON.stringify(response) + '\n');
  }
}

if (process.argv[2] === '--serve') {
  serve();
} else {
  const result = dumpFile(process.argv[2]);
  if (result.code !== 0) {
    process.stderr.write(result.error + '\n');
    process.exit(result.code);
  }
  process.stdout.write(JSON.stringify(result.ast));
}
