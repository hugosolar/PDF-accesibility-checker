/**
 * Script to copy over Apps Scripts in Google Sheets
 * This script copies data from the "PDFs report" sheet to all other sheets in the spreadsheet
 */
function copyDataFromPDFsReport() {
  // Open the active spreadsheet
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  
  // Get the "PDFs report" sheet
  const pdfsReportSheet = ss.getSheetByName("PDFs report");
  if (!pdfsReportSheet) {
    throw new Error("Sheet named 'PDFs report' does not exist.");
  }

  // Get data from the "PDFs report" sheet
  const pdfsReportData = pdfsReportSheet.getDataRange().getValues();
  const pdfsReportHeaders = pdfsReportData[0];
  
  // Columns we want to copy from "PDFs report"
  const columnsToCopy = ["Description", "Needs manual check", "Passed manually", "skipped", "passed", "failed", "Full Report", "Document/Accessibility permission flag", "Document/Image-only PDF", "Document/Tagged PDF", "Document/Logical Reading Order", "Document/Primary language", "Document/Title", "Document/Bookmarks", "Document/Color contrast", "Page Content/Tagged content", "Page Content/Tagged annotations", "Page Content/Tab order", "Page Content/Character encoding", "Page Content/Tagged multimedia", "Page Content/Screen flicker", "Page Content/Scripts", "Page Content/Timed responses", "Page Content/Navigation links", "Forms/Tagged form fields", "Forms/Field descriptions", "Alternate Text/Figures alternate text", "Alternate Text/Nested alternate text", "Alternate Text/Associated with content", "Alternate Text/Hides annotation", "Alternate Text/Other elements alternate text", "Tables/Rows", "Tables/TH and TD", "Tables/Headers", "Tables/Regularity", "Tables/Summary", "Lists/List items", "Lists/Lbl and LBody", "Headings/Appropriate nesting"];
  const columnIndicesToCopy = columnsToCopy.map(header => pdfsReportHeaders.indexOf(header) + 1);

  // Loop through all sheets in the spreadsheet
  const sheets = ss.getSheets();
  sheets.forEach(sheet => {
    if (sheet.getName() !== "PDFs report") {
      const data = sheet.getDataRange().getValues();
      const urlColumn = data.map(row => row[1]); // Column 2

      // Prepare data to update
      const updatedData = data.map((row, rowIndex) => {
        if (rowIndex === 0) return [...row, ...columnsToCopy]; // Add headers to the first row
        
        const pdfUrl = row[1]; // Column 2
        const matchingRow = pdfsReportData.find(pdfRow => pdfRow[0] === pdfUrl); // Match URL in column 2

        if (matchingRow) {
          const additionalData = columnIndicesToCopy.map(index => matchingRow[index - 1]);
          return [...row, ...additionalData];
        }
        return [...row, ...Array(columnsToCopy.length).fill("")];
      });

      // Update the current sheet
      sheet.clear();
      sheet.getRange(1, 1, updatedData.length, updatedData[0].length).setValues(updatedData);
    }
  });
}
