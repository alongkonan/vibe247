// ตัวแปรเก็บความจำชั่วคราว (จะอยู่ได้นานตราบใดที่เว็บยังดึงข้อมูลเรื่อยๆ)
let currentSong = "";
let lastUpdateTime = 0;

export default function handler(req, res) {
    // ป้องกันปัญหา CORS อนุญาตให้เว็บ VIBE24 ดึงข้อมูลได้
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

    // 1. ส่วนส่งข้อมูลให้เว็บไซต์ VIBE24 นำไปใช้ (GET)
    if (req.method === 'GET' && req.query.action === 'get') {
        const isOnline = (Date.now() - lastUpdateTime) < 600000; // 10 นาที
        return res.status(200).json({
            status: isOnline ? 'online' : 'offline',
            song: isOnline ? currentSong : ''
        });
    }

    // 2. ส่วนรับข้อมูลจากโปรแกรม RadioDJ (รองรับ POST เหมือนในคลิป)
    // อ่านข้อมูลจาก Body (Custom Data) หรือ Query (URL)
    const data = req.method === 'POST' ? req.body : req.query;
    
    // อ่าน Key จาก URL เพื่อความปลอดภัย
    const key = req.query.key;

    if (key !== 'vibe24') {
        return res.status(403).send('Invalid Key');
    }

    // ดึงค่า artist และ title ที่ RadioDJ ส่งมา
    const artist = data.artist;
    const title = data.title;

    // เช็คว่าส่งข้อมูลมาจริง และไม่ใช่ตัวแปรดิบๆ ($artist$)
    if (artist && title && artist !== '$artist$') {
        currentSong = `${artist} - ${title}`;
        lastUpdateTime = Date.now();
        return res.status(200).send(`Success: ${currentSong}`);
    }

    return res.status(200).send('VIBE24 RadioDJ API is Ready. Waiting for POST data...');
}
