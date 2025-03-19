/*
 * PDF accessibility checker using Adobe PDF Services SDK
 * This script performs accessibility check on a PDF file and saves the report to a JSON file.
 */

const {
    ServicePrincipalCredentials,
    PDFServices,
    MimeType,
    SDKError,
    ServiceUsageError,
    ServiceApiError,
    PDFAccessibilityCheckerJob,
    PDFAccessibilityCheckerResult
} = require("@adobe/pdfservices-node-sdk");
const fs = require("fs");
const path = require('path');

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

/**
 * Perform accessibility check on a PDF file and save the report to a JSON file.
 * The input file path is provided as a command line argument.
 * The output file is saved in the output/PDFAccessibilityChecker directory.
 */
(async () => {
    let readStream;
    try {
        // Initial setup, create credentials instance with environment variables
        const credentials = new ServicePrincipalCredentials({
            clientId: process.env.PDF_SERVICES_CLIENT_ID,
            clientSecret: process.env.PDF_SERVICES_CLIENT_SECRET
        });

        // Creates a PDF Services instance
        const pdfServices = new PDFServices({credentials});

        // Creates an asset(s) from source file(s) and upload
        const fileName = process.argv[2];
        if ( !fileName ) {
            throw new Error("Input file not specified");
        }
        // Creates an output stream and copy result asset's content to it
        const testFileName = path.basename(fileName);
        const outputDirectory = path.resolve(__dirname, 'output/PDFAccessibilityChecker');
        const $jsonFilePath = findFileByName(outputDirectory, testFileName);
        
        if ( fs.existsSync($jsonFilePath) ) {
            throw new Error("File aleady exists in reports");
        }
        
        readStream = fs.createReadStream(fileName);
        const inputAsset = await pdfServices.upload({
            readStream,
            mimeType: MimeType.PDF
        });

        // Create a new job instance
        const job = new PDFAccessibilityCheckerJob({inputAsset});

        // Submit the job and get the job result
        const pollingURL = await pdfServices.submit({job});
        const pdfServicesResponse = await pdfServices.getJobResult({
            pollingURL,
            resultType: PDFAccessibilityCheckerResult
        });

        const resultAssetReport = pdfServicesResponse.result.report;
        const streamAssetReport = await pdfServices.getContent({asset: resultAssetReport});
        const outputFilePathReport = createOutputFilePath(fileName, ".json");

        console.log(`Saving asset at ${outputFilePathReport}`);

        writeStream = fs.createWriteStream(outputFilePathReport);
        streamAssetReport.readStream.pipe(writeStream);
    } catch (err) {
        if (err instanceof SDKError || err instanceof ServiceUsageError || err instanceof ServiceApiError) {
            console.log("Exception encountered while executing operation", err);
        } else {
            console.log("Exception encountered while executing operation", err);
        }
    } finally {
        readStream?.destroy();
    }
})();

// Generates a string containing a directory structure and file name for the output file
function createOutputFilePath(fileName, extension) {
    const filePath = "output/PDFAccessibilityChecker/";
    const fileNamePath = path.basename(fileName);
    const date = new Date();
    const dateString = date.getFullYear() + "-" + ("0" + (date.getMonth() + 1)).slice(-2) + "-" +
        ("0" + date.getDate()).slice(-2) + "T" + ("0" + date.getHours()).slice(-2) + "-" +
        ("0" + date.getMinutes()).slice(-2) + "-" + ("0" + date.getSeconds()).slice(-2);
    fs.mkdirSync(filePath, {recursive: true});
    return (`${filePath}accessibilityChecker-${fileNamePath}-${dateString}${extension}`);
}
