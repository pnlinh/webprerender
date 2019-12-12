const puppeteer = require('puppeteer');
const fse = require('fs-extra');

if (process.argv.length < 3) {
    console.log('Please enter URL');

    return;
}

const url = process.argv.slice(2).join('');
const fullDomainName = getFullDomainName(url);

if ('' === url || !isURL(url)) {
    console.log('URL is not valid!');

    return;
}

/**
 * @see https://stackoverflow.com/questions/8498592/extract-hostname-name-from-string
 * @param url
 */
function getFullDomainName(url) {
    const matches = url.match(/^https?\:\/\/([^\/?#]+)(?:[\/?#]|$)/i);

    return matches && matches[0];
}

/**
 * @see https://www.regextester.com/93652
 * @param url
 */
function isURL(url) {
    const urlRegex = '^(http:\\/\\/www\\.|https:\\/\\/www\\.|http:\\/\\/|https:\\/\\/)?[a-z0-9]+([\\-\\.]{1}[a-z0-9]+)*\\.[a-z]{2,5}(:[0-9]{1,5})?(\\/.*)?$';
    const regex = new RegExp(urlRegex, 'gm');

    return regex.test(url);
}

async function start(url) {
    const browser = await puppeteer.launch();
    const page = await browser.newPage();

    await page.goto(url, {
        waitUntil: 'networkidle2'
    });

    // await page.goto(url);
    const content = await page.content();

    // Remove scrip tag
    const scriptTagRegex = new RegExp('<script\\b[^<]*(?:(?!<\\/script>)<[^<]*)*<\\/script>', 'gi');
    let result = content.replace(scriptTagRegex, '');

    // Replace css
    const cssRegex = new RegExp('href=\\"\\/(\\S*)\\.css|\\?v=\\"', 'gi');
    result = result.replace(cssRegex, `href="${fullDomainName}$1.css`);

    // Replace cdn image
    const cdnRegex = new RegExp('src=\\"\\/\\/(\\S.*)\\.(png|jpg)\\"', 'gi');
    result = result.replace(cdnRegex, `src="https://$1.$2"`);

    // Replace normal image
    const normalImageRegex = new RegExp('src=\\"\\/([^\\/]\\S.*)\\.(png|jpg|svg|gif)\\"', 'gi');
    result = result.replace(normalImageRegex, `src="${fullDomainName}$1.$2"`);

    // Replace svg icon
    const iconRegex = new RegExp('url\\(\\/(\\S.*)\\.(svg)\\?v=\\d+\\)', 'gi');
    result = result.replace(iconRegex, `url(${fullDomainName}$1.$2)`);

    // Append comment for quick check page rendered via puppeteer
    result = `<!-- Render Page Caching by Puppeteer --> ${result}`;

    const arrPath = url.split('/');
    let fileName = arrPath[arrPath.length - 1];

    if (fileName.match(new RegExp('.html|.htm','g'))) {
        const removeDotEndPath = fileName.split('.');

        fileName = removeDotEndPath.shift();
    }

    // Return result first
    console.log(result);

    await fse.outputFile(`${__dirname}/public/pages/${fileName}.html`, result);

    await browser.close();
}

try {
    start(url);
} catch (e) {
    console.log(e.message);
}

