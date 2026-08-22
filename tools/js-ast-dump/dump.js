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

const filePath = process.argv[2];
if (!filePath) {
  process.stderr.write('Використання: node dump.js <файл.js|.ts|...>\n');
  process.exit(2);
}

let source;
try {
  source = fs.readFileSync(filePath, 'utf8');
} catch (e) {
  process.stderr.write(`Не вдалось прочитати файл: ${e.message}\n`);
  process.exit(2);
}

const isTsx = filePath.endsWith('.tsx') || filePath.endsWith('.jsx');
const scriptKind = isTsx ? ts.ScriptKind.TSX : ts.ScriptKind.TS;

const sourceFile = ts.createSourceFile(
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
// виходу 3 + повідомлення в stderr, а не мовчазне "порожнє" AST.
const diagnostics = sourceFile.parseDiagnostics || [];
if (diagnostics.length > 0) {
  const first = diagnostics[0];
  const { line } = sourceFile.getLineAndCharacterOfPosition(first.start || 0);
  const message = ts.flattenDiagnosticMessageText(first.messageText, '\n');
  process.stderr.write(`Parse Error: рядок ${line + 1}: ${message}\n`);
  process.exit(3);
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

const root = {
  type: 'Root',
  line: 1,
  attributes: {},
  children: [mapStatementList(sourceFile.statements, 1)],
};

process.stdout.write(JSON.stringify(root));
