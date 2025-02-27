const fs = require('fs');
const path = require('path');
const csv = require('csv-parser');
const { createObjectCsvWriter } = require('csv-writer');

function findFileByName(directoryPath, searchString) {
    // Check if the directory exists
    if (!fs.existsSync(directoryPath)) {
        console.error(`Directory not found: ${directoryPath}`);
        return null;
    }

    // Read the directory
    const files = fs.readdirSync(directoryPath);

    // Loop through all the files in the directory
    for (const file of files) {
        // Check if the file name contains the search string
        if (file.includes(searchString)) {
            // Return the full path of the found file
            return path.join(directoryPath, file);
        }
    }

    // Return null if no file is found
    return null;
}

function findFirstJsonFile(directoryPath) {
    // Check if the directory exists
    if (!fs.existsSync(directoryPath)) {
        console.error(`Directory not found: ${directoryPath}`);
        return null;
    }

    // Read the directory
    const files = fs.readdirSync(directoryPath);

    // Loop through all the files in the directory
    for (const file of files) {
        // Check if the file has a .json extension
        if (file.endsWith('.json')) {
            // Return the full path of the found file
            return path.join(directoryPath, file);
        }
    }

    // Return null if no file is found
    return null;
}

function extractHeadersFromJson(jsonFilePath) {
    const jsonData = JSON.parse(fs.readFileSync(jsonFilePath, 'utf8'));
    const detailedReport = jsonData['Detailed Report'];
    const categories = Object.keys(detailedReport);
    let headers = [];

    categories.forEach(category => {
        detailedReport[category].forEach(item => {
            headers.push(category + '/' + item['Rule']);
        });
    });

    // Remove duplicate headers
    headers = [...new Set(headers)];
    return headers;
}

let handleName = process.argv[2];
if ( !handleName ) {
    handleName = 'file_list_1';
    //throw new Error("Input handle not specified");
}

const inputCsvPath = path.resolve( __dirname, handleName + '.csv' );
const pdfDirectory = handleName;
const outputDirectory = path.resolve(__dirname, 'output/PDFAccessibilityChecker');
const outputCsvDirectory = path.resolve(__dirname, 'output/'+handleName);
const outputCsvPath = path.resolve(__dirname, 'output/'+handleName+'/output.csv');

// Create the output directory if it doesn't exist
if (!fs.existsSync(outputCsvDirectory)) {
    fs.mkdirSync(outputCsvDirectory, { recursive: true });
}

// Create the output file if it doesn't exist
if (!fs.existsSync(outputCsvPath)) {
    fs.writeFileSync(outputCsvPath, '');
}

// Generate CSV headers from the first JSON file in the directory
const firstJsonFilePath = findFirstJsonFile(outputDirectory);
let headers = [
    { id: 'url', title: 'url' },
    { id: 'date', title: 'date' },
    { id: 'title', title: 'title' },
    { id: 'post_url', title: 'post_url' },
    { id: 'Description', title: 'Description' },
    { id: 'Needs_manual_check', title: 'Needs manual check' },
    { id: 'Passed_manually', title: 'Passed manually' },
    { id: 'failed_manually', title: 'failed manually' },
    { id: 'skipped', title: 'skipped' },
    { id: 'passed', title: 'passed' },
    { id: 'failed', title: 'failed' },
    { id: 'full_report', title: 'Full Report' }
];

if (firstJsonFilePath) {
    const additionalHeaders = extractHeadersFromJson(firstJsonFilePath).map(rule => ({
        id: rule,
        title: rule
    }));
    headers = headers.concat(additionalHeaders);
}

const csvWriter = createObjectCsvWriter({
    path: outputCsvPath,
    header: headers
});

const results = [];
let isFirstRow = true;

fs.createReadStream(inputCsvPath)
    .pipe(csv())
    .on('data', (row) => {
        const url = row.url;
        let pdfFileName;

        if (url.includes('aka.ms')) {
            const lastSegment = url.split('/').pop();
            pdfFileName = `${lastSegment}.pdf`;
        } else {
            pdfFileName = path.basename(url);
        }

        // Replace spaces in the PDF file name with underscores
        pdfFileName = pdfFileName.replace(/ /g, '_').replace(/%20/g, '_');

        const pdfFilePath = path.resolve(__dirname, path.join(pdfDirectory, pdfFileName));
        const $jsonFilePath = findFileByName(outputDirectory, pdfFileName);

        if (fs.existsSync($jsonFilePath)) {
            const jsonData = JSON.parse(fs.readFileSync($jsonFilePath, 'utf8'));
            const summary = jsonData.Summary;
            const detailedReport = jsonData['Detailed Report'];
            const categories = Object.keys(detailedReport);
            let rowData = {
                url: row.url,
                date: row.date,
                title: row.title,
                post_url: row.post_url,
                Description: summary.Description,
                Needs_manual_check: summary['Needs manual check'],
                Passed_manually: summary['Passed manually'],
                failed_manually: summary['Failed manually'],
                skipped: summary['Skipped'],
                passed: summary['Passed'],
                failed: summary['Failed'],
                full_report: path.basename($jsonFilePath)
            };

            categories.forEach(category => {
                detailedReport[category].forEach(item => {
                    const rule = item['Rule'];
                    const statusDescription = `${item['Status']} - ${item['Description']}`;
                    rowData[category + '/' + rule] = statusDescription;
                });
            });

            results.push(rowData);

        } else {
            let rowData = {
                url: row.url,
                date: row.date,
                title: row.title,
                post_url: row.post_url,
                Description: '',
                Needs_manual_check: '',
                Passed_manually: '',
                failed_manually: '',
                skipped: '',
                passed: '',
                failed: '',
                full_report: ''
            };

            headers.slice(12).forEach(header => {
                rowData[header.id] = '';
            });

            results.push(rowData);
        }
    })
    .on('end', () => {
        csvWriter.writeRecords(results)
            .then(() => {
                console.log('The CSV file was written successfully');
            });
    });