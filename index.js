const puppeteer = require('puppeteer');
const fse = require('fs-extra');

const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1';

// Handle process on exception
process.on('uncaughtException', (err) => {
    console.error(err, err.message);
    process.exit(1);
});

// Handle process on rejection
process.on('unhandledRejection', (err) => {
    console.error(err, err.message);
    process.exit(1);
});

if (process.argv.length < 3) {
    console.error('Please enter URL');

    return;
}

const url = process.argv[2];
const isMobile = Number(process.argv[3]);
let fullDomainName = getFullDomainName(url);

if (!fullDomainName.endsWith('/')) {
    fullDomainName = fullDomainName + '/';
}

let dirPath = fullDomainName.replace(new RegExp('http(s?)\:\/\/', 'g'), '');
dirPath = dirPath.replace('/', '');
dirPath = dirPath.replace(/\.|\:/g, '-');

if ('' === url || !isURL(url)) {
    console.error(`URL is not valid! - ${url}`);

    return;
}

function today() {
    const today = new Date();

    const date = today.getFullYear() + '-' + (today.getMonth() + 1) + '-' + today.getDate();
    const time = today.getHours() + ":" + today.getMinutes() + ":" + today.getSeconds();

    return date + ' ' + time;
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

async function start(url, isMobile = 0, timeout = 5000) {
    const browser = await puppeteer.launch({timeout: 0, args: ['--no-sandbox']});
    const page = await browser.newPage();

    if (isMobile) {
        await page.setUserAgent(MOBILE_USER_AGENT);
    }

    await page.goto(url, {
        waitUntil: 'networkidle2'
    });

    await page.waitFor(timeout);

    const content = await page.content();

    await browser.close();

    // Remove script tag
    const scriptTagRegex = new RegExp('<script\\b[^<]*(?:(?!<\\/script>)<[^<]*)*<\\/script>', 'gi');
    let result = content.replace(scriptTagRegex, (matched, index, original) => {
        if (matched.includes('json')) {
            return matched;
        }

        return '';
    });

    // Replace css
    const cssRegex = new RegExp('href=\\"\\/(\\S*)\\.css|\\?v=\\"', 'gi');
    result = result.replace(cssRegex, (matched, index, original) => {
        let flag = fullDomainName.replace(new RegExp('http(s?):\\/\\/'), '');

        if (matched.includes(flag)) {
            matched = matched.replace('//', 'https://');

            return matched;
        }

        return `href="${fullDomainName}${index}.css`;
    });

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
    result = `<!-- Render Page Caching by Puppeteer at ${today()} --> ${result}`.trim();

    const arrPath = url.split('/');
    let fileName = arrPath[arrPath.length - 1];

    const queryStringRegex = new RegExp('\\?.*', 'g');
    if (fileName.match(queryStringRegex)) {
        fileName = fileName.replace(queryStringRegex, '');
    }

    if (fileName.match(new RegExp('.html|.htm', 'g'))) {
        const removeDotEndPath = fileName.split('.');

        fileName = removeDotEndPath.shift();
    }

    // Return result first
    console.log(result);

    if (isMobile) {
        await fse.outputFile(`${__dirname}/public/pages/${dirPath}/mobile/${fileName}.html`, result);
    } else {
        await fse.outputFile(`${__dirname}/public/pages/${dirPath}/desktop/${fileName}.html`, result);
    }
}

try {
    start(url, isMobile);
} catch (e) {
    console.error(e.message);
}

